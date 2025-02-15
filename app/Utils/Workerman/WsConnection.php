<?php

namespace App\Utils\Workerman;

use App\Jobs\UpdateCurrencyPrice;
use Illuminate\Support\Facades\Redis;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use App\{Currency, CurrencyMatch, CurrencyQuotation, MarketHour, MyQuotation, Needle, Setting, UserChat};
use App\Jobs\{EsearchMarket, LeverUpdate, LeverPushPrice, SendMarket, WriteMarket, HandleMicroTrade};

class WsConnection
{
    protected $server_address = 'ws://api.huobi.pro:443/ws';
//    protected $server_address = 'ws://api.huobi.br.com:443/'; //ws国内开发调试
    protected $server_ping_freq = 5; //服务器ping检测周期,单位秒
    protected $server_time_out = 2; //服务器响应超时
    protected $send_freq = 2; //写入和发送数据的周期，单位秒
    protected $micro_trade_freq = 1; //秒合约处理时间周期

    protected $worker_id;

    protected $events = [
        'onConnect',
        'onClose',
        'onMessage',
        'onError',
        'onBufferFull',
        'onBufferDrain',
    ];

    protected $connection;

    //定时器
    protected $timer;
    //ping定时器
    protected $pingTimer;
    //定时从redis读取数据
    protected $updatePriceTimer;
    protected $updateDepthTimer;
    protected $updateDetailTimer;
    //发送k线定时器
    protected $sendKlineTimer;
    //发送市场深度定时器
    protected $sendDepthTimer;
    //深度定时器
    protected $depthTimer;
    //交易详情定时器
    protected $detailTimer;
    //处理杠杆交易定时器
    protected $handleTimer;
    //处理秒订单定时器
    protected $microTradeHandleTimer;

    //已订阅
    protected $subscribed = [];
    protected $marketKlineData = [];
    protected $marketDepthData = [];
    protected $marketDepthDetail = [];

    protected $writeRedisTimer;
    protected $iepnDepthTimer;
    //读取已有币种
    protected $currencys = [];
    protected $periods = ['1min', '5min', '15min', '30min', '60min', '1day', '1mon', '1week'];

    //订阅模板
    protected $topicTemplate = [
        'sub' => [
            'market_kline' => 'market.$symbol.kline.$period',
            'market_detail' => 'market.$symbol.trade.detail',
            'market_depth' => 'market.$symbol.depth.$type',
        ],
    ];

    public function __construct($worker_id)
    {
        $this->worker_id = $worker_id;
        AsyncTcpConnection::$defaultMaxPackageSize = 1048576000;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * 绑定所有事件到连接
     *
     * @return void
     */
    protected function bindEvent()
    {
        foreach ($this->events as $key => $event) {
            if (method_exists($this, $event)) {
                $this->connection && $this->connection->$event = [$this, $event];
                //echo '绑定' . $event . '事件成功' . PHP_EOL;
            }
        }
    }

    /**
     * 解除连接所有绑定事件
     *
     * @return void
     */
    protected function unBindEvent()
    {
        foreach ($this->events as $key => $event) {
            if (method_exists($this, $event)) {
                $this->connection && $this->connection->$event = null;
                //echo '解绑' . $event . '事件成功' . PHP_EOL;
            }
        }
    }

    public function getSubscribed($topic = null)
    {
        if (is_null($topic)) {
            return $this->subscribed;
        }
        return $this->subscribed[$topic] ?? null;
    }

    protected function setSubscribed($topic, $value)
    {
        $this->subscribed[$topic] = $value;
    }

    protected function delSubscribed($topic)
    {
        unset($this->subscribed[$topic]);
    }

    public function connect()
    {
        if ($this->worker_id < 10) {
            $this->onConnect(null);
        } else {

            $this->connection = new AsyncTcpConnection($this->server_address);
            $this->bindEvent();
            $this->connection->transport = 'ssl';
            $this->connection->connect();
        }
    }

    public function onConnect($con)
    {
        //连接成功后定期发送ping数据包检测服务器是否在线

        $this->currencys = Currency::all()->toArray();

        if ($this->worker_id < 8) {


            $this->updatePriceTimer = Timer::add($this->micro_trade_freq, [$this, 'onMarketKline'], [], true);
            $this->sendKlineTimer = Timer::add($this->micro_trade_freq, [$this, 'writeMarketKline'], [], true);


        } else {
//            $this->timer = Timer::add($this->server_ping_freq, [$this, 'ping'], [$this->connection], true);

            $this->updatePriceTimer = Timer::add($this->micro_trade_freq, [$this, 'onMarketDepth'], [], true);
            $this->updatePriceTimer = Timer::add($this->micro_trade_freq, [$this, 'onMarketDetail'], [], true);

            $this->depthTimer = Timer::add($this->micro_trade_freq, [$this, 'sendDepthData'], [], true);
            $this->detailTimer = Timer::add($this->micro_trade_freq, [$this, 'sendDetailData'], [], true);


//            $this->writeRedisTimer = Timer::add($this->micro_trade_freq, [$this, 'writeRedis'], [], true);

//            $this->iepnDepthTimer = Timer::add(3, [$this, 'sendDepthAndDetail'], [], true);
        }

        if ($this->worker_id == 0) {
            $this->handleTimer = Timer::add($this->send_freq, [$this, 'sendLeverHandle'], [], true);
        }

        if ($this->worker_id == 0) {
            $this->microTradeHandleTimer = Timer::add($this->micro_trade_freq, [$this, 'handleMicroTrade'], [], true);
        }
        //添加订阅事件代码
        $this->subscribe($con);
    }

    public function onClose($con)
    {
        echo $this->server_address . '连接关闭' . PHP_EOL;
        $path = base_path() . '/storage/logs/wss/';
//        $filename = date('Ymd') . '.log';
        file_exists($path) || @mkdir($path);
//        error_log(date('Y-m-d H:i:s') . ' ' . $this->server_address . '连接关闭' . PHP_EOL, 3, $path . $filename);
        //解除事件
        $this->timer && Timer::del($this->timer);
        $this->updatePriceTimer && Timer::del($this->updatePriceTimer);
        $this->sendKlineTimer && Timer::del($this->sendKlineTimer);
        $this->pingTimer && Timer::del($this->pingTimer);
        $this->depthTimer && Timer::del($this->depthTimer);
        $this->handleTimer && Timer::del($this->handleTimer);
        $this->writeRedisTimer && Timer::del($this->writeRedisTimer);

        $this->unBindEvent();
        unset($this->connection);
        $this->connection = null;
        $this->subscribed = null; //清空订阅
        echo '尝试重新连接' . PHP_EOL;
        $this->connect();
    }

    public function close($msg)
    {
        $path = base_path() . '/storage/logs/wss/';
        $filename = date('Ymd') . '.log';
        file_exists($path) || @mkdir($path);
        error_log(date('Y-m-d H:i:s') . ' ' . $msg, 3, $path . $filename);
        $this->connection->destroy();
    }

    protected function makeSubscribeTopic($topic_template, $param)
    {
        $need_param = [];
        $match_count = preg_match_all('/\$([a-zA-Z_]\w*)/', $topic_template, $need_param);
        if ($match_count > 0 && count(reset($need_param)) > count($param)) {
            throw new \Exception('所需参数不匹配');
        }
        $diff = array_diff(next($need_param), array_keys($param));
        if (count($diff) > 0) {
            throw new \Exception('topic:' . $topic_template . '缺少参数：' . implode(',', $diff));
        }
        return preg_replace_callback('/\$([a-zA-Z_]\w*)/', function ($matches) use ($param) {
            extract($param);
            $value = $matches[1];
            return $$value ?? '';
        }, $topic_template);
    }

    public function onBufferFull()
    {
        echo 'buffer is full' . PHP_EOL;
    }

    protected function subscribe($con)
    {
        $periods = ['1min', '5min', '15min', '30min', '60min', '1day', '1mon', '1week']; //['1day', '1min'];

        if ($this->worker_id < 8) {
            $this->subscribeKline($con, $periods[$this->worker_id]);
        } else {

            $this->subscribeMarketDepth($con); //订阅盘口数据
            $this->subscribeMarketDetail($con);

        }
    }

    //订阅回调
    protected function onSubscribe($data)
    {
        if ($data->status == 'ok') {
            echo $data->subbed . '订阅成功' . PHP_EOL;
        } else {
            echo '订阅失败:' . $data->{'err-msg'} . PHP_EOL;
        }
    }

    //订阅K线行情
    protected function subscribeKline($con, $period)
    {

        $currency_match = CurrencyMatch::getHuobiMatchs();
        echo '火币对数量' . count($currency_match) . "\r\n";
//        var_dump($currency_match);
//        dd($currency_match);
//        dd(count($currency_match));
        foreach ($currency_match as $key => $value) {
            $param = [
                'symbol' => $value->match_name,
                'period' => $period,
            ];
//            var_dump($value->match_name);
            $topic = $this->makeSubscribeTopic($this->topicTemplate['sub']['market_kline'], $param);

            $sub_data = json_encode([
                'sub' => $topic,
                'id' => $topic,
                //'freq-ms' => 5000, //推送频率，实测只能是0和5000，与官网文档不符
            ]);
            //未订阅过的才能订阅
            if (is_null($this->getSubscribed($topic))) {
                $this->setSubscribed($topic, [
                    'callback' => 'onMarketKline',
                    'match' => $value
                ]);
//                usleep(200000);
//                $con->send($sub_data);
            }
        }
    }

    public function onMarketKline()
    {

        foreach ($this->currencys as $currency) {
            $currency_name = strtolower($currency['name'] . 'usdt');
            $key = "market.{$currency_name}.kline.{$this->periods[$this->worker_id]}";

            $obj = Redis::get($key);


            $data = json_decode($obj);

            if (is_null($data)) {
                continue;
            }
//            var_dump($data);

            $topic = $data->ch;
            list($name, $symbol, $detail_name, $period) = explode('.', $topic);

            $current = time();
            $needles = Needle::where('symbol', strtoupper($currency['name']) . '/USDT')->where('itime', $current - ($current % 60))->get();
//            echo count($needles) . $period . "\r\n";


            $subscribed_data = $this->getSubscribed($topic);
            $currency_match = $subscribed_data['match'];

            if (!isset($currency_match)) {
                return;
            }
            $tick = $data->tick;
            $open = $tick->open;
            $high = $tick->high;
            $low = $tick->low;
            $close = $tick->close;
            if (count($needles) > 0) {
                $needle = $needles[0];

                echo '出现针' . $needle['itime'];
                $itime = strtotime($needle['itime']);
                switch ($period) {
                    case "1min":
                        if ($current - $itime < 40 && $current - $itime > 1) {
                            $open = $needle['open'];
                            $high = $needle['high'];
                            $low = $needle['low'];
                            $close = $needle['close'];
                        }
                        break;
                    default:
                        if ($current - $itime < 40 && $current - $itime > 1) {
                            $open = min($needle['open'], $open);
                            $high = $needle['high'] > $high ? $needle['high'] : $high;
                            $low = $needle['low'] < $high ? $needle['low'] : $high;
                            $close = min($needle['close'], $close);
                        }
                        break;
                }

            }

            $market_data = [
                'id' => $tick->id,
                'period' => $period,
                'base-currency' => $currency_match->currency_name,
                'quote-currency' => $currency_match->legal_name,
                'open' => sctonum($open),
                'close' => sctonum($close),
                'high' => sctonum($high),
                'low' => sctonum($low),
                'vol' => sctonum($tick->vol),
                'amount' => sctonum($tick->amount),
            ];

            $kline_data = [
                'type' => 'kline',
                'period' => $period,
                'match_id' => $currency_match->id,
                'currency_id' => $currency_match->currency_id,
                'currency_name' => $currency_match->currency_name,
                'legal_id' => $currency_match->legal_id,
                'legal_name' => $currency_match->legal_name,
                'open' => $open,
                'close' => $close,
                'high' => $high,
                'low' => $low,
                'symbol' => $currency_match->currency_name . '/' . $currency_match->legal_name,
                'volume' => sctonum($tick->amount),
                'time' => $tick->id * 1000,
            ];
            if ($period == '1min' && $currency_match->currency_id === 24) {
                echo 'BCI行情' . $kline_data['close'] . "\r\n";
            }
//            EsearchMarket::dispatch($market_data)->onQueue('esearch:market:' . $period);
            $key = $currency_match->currency_name . '.' . $currency_match->legal_name;
            $this->marketKlineData[$period][$key] = [
                'market_data' => $market_data,
                'kline_data' => $kline_data ?? [],
            ];
            if ($period == '1day') {
                //推送币种的日行情(带涨副)
                $change = $this->calcIncreasePair($kline_data ?? []);
                bc_comp($change, 0) > 0 && $change = '+' . $change;
                //追加涨副等信息
                $daymarket_data = [
                    'type' => 'daymarket',
                    'change' => $change,
                    'now_price' => $market_data['close'],
                    'api_form' => 'huobi_websocket',
                ];
                $kline_data = array_merge($kline_data, $daymarket_data);
                $this->marketKlineData[$period][$key]['kline_data'] = $kline_data;

            }
        }
    }

    public function writeRedis()
    {

        $plat_formscurrency = Setting::getValueByKey('platform_currenys');
        foreach ($this->periods as $key => $val) {

            $sy = strtolower($plat_formscurrency . 'usdt');
            $rkey = "market.{$sy}.kline.{$val}";
//            var_dump($rkey);
            if ($key <= 5) {
                if ($val === '1min') {
                    $now = date('Y-m-d H:i') . ":00";
                    $timemicro = intval(microtime(true) * 1000);
                    $mine = MyQuotation::where('itime', strtotime($now))->where('symbol', strtoupper($plat_formscurrency . '/USDT'))->first();

                    if (!$mine) {
                        continue;
                    }
                    Redis::set($rkey, json_encode(['ch' => $rkey, 'ts' => $timemicro, 'tick' => [
                        'id' => strtotime($mine->itime),
                        'open' => $mine->open,
                        'close' => $mine->close + (rand(-9, 9) * 0.000001),
                        'low' => $mine->low,
                        'high' => $mine->high,
                        'vol' => $mine->vol,
                        'amount' => $mine->vol,
                        'count' => rand(10, 120)
                    ]]));
                } else {
                    $timemicro = intval(microtime(true) * 1000);
                    $base_s = 300;
                    if ($val === '5min') {
                        $base_s = 300;
                    }
                    if ($val === '15min') {
                        $base_s = 900;
                    }
                    if ($val === '30min') {
                        $base_s = 1800;
                    }
                    if ($val === '60min') {
                        $base_s = 2700;
                    }
                    if ($val === '1day') {
                        $base_s = 2700 * 22;
                    }

                    $time = time();
                    $start_time = $time - ($time % $base_s);

                    $mine = MyQuotation::where('itime', $start_time)->where('symbol', strtoupper($plat_formscurrency . '/USDT'))->first();
                    if(is_null($mine))
                    {

                        $mine = MyQuotation::where('symbol', strtoupper($plat_formscurrency . '/USDT'))->orderBy('id','asc')->first();
                    }
                    $high = MyQuotation::whereBetween('itime', [$start_time - 1, time()])->where('symbol', strtoupper($plat_formscurrency . '/USDT'))->max('high');
                    $low = MyQuotation::whereBetween('itime', [$start_time - 1, time()])->where('symbol', strtoupper($plat_formscurrency . '/USDT'))->min('low');
                    $vol = MyQuotation::whereBetween('itime', [strtotime('-' . $base_s . ' seconds'), time()])->where('symbol', strtoupper($plat_formscurrency . '/USDT'))->sum('vol');
                    $last = MyQuotation::where('itime', $start_time + ($base_s - 60))->where('symbol', strtoupper($plat_formscurrency . '/USDT'))->first(['close']);

                    $close = $last ? $last->close : $mine->close;

                    Redis::set($rkey, json_encode(['ch' => $rkey, 'ts' => $timemicro, 'tick' => [
                        'id' => strtotime($mine->itime),
                        'open' => $mine->open,
                        'close' => $close + (rand(-9, 9) * 0.000001),
                        'low' => $low,
                        'high' => $high,
                        'vol' => $vol + (rand(0, 9) * 100),
                        'amount' => $vol + (rand(0, 9) * 100),
                        'count' => $vol
                    ]]));
                }
            }
        }
    }


    public function writeMarketKline()
    {
        if ($this->worker_id < 8) {
            $market_data = $this->marketKlineData;
            foreach ($market_data as $period => $data) {
                foreach ($data as $key => $symbol) {
//                    echo '处理' . $key . '.' . $period . '数据' . PHP_EOL;
//                    $result = MarketHour::getEsearchMarketById(
//                        $symbol['market_data']['base-currency'],
//                        $symbol['market_data']['quote-currency'],
//                        $period,
//                        $symbol['market_data']['id']
//                    );
////
//                    if (isset($result['_source'])) {
//                        $origin_data = $result['_source'];
//                        bc_comp($symbol['kline_data']['high'], $origin_data['high']) < 0
//                        && $symbol['kline_data']['high'] = $origin_data['high']; //新过来的价格如果不高于原最高价则不更新
//                        bc_comp($symbol['kline_data']['low'], $origin_data['low']) > 0
//                        && $symbol['kline_data']['low'] = $origin_data['low']; //新过来的价格如果不低于原最低价则不更新
//                    }


                    if (isset($symbol['kline_data']['type']) && $symbol['kline_data']['type'] == 'kline') {
                    }
                    SendMarket::dispatch($symbol['kline_data'])->onQueue('kline.all');
//                    EsearchMarket::dispatch($symbol['market_data'])->onQueue('esearch:market:' . $period);

                    //加入插针判断点

                    if ($period == '1min') {
                        // var_dump($symbol['kline_data']);
                        //推送一分钟行情
                        //SendMarket::dispatch($symbol['kline_data'])->onQueue('kline.1min');
                        //更新币种价格
                        UpdateCurrencyPrice::dispatch($symbol['kline_data'])->onQueue('update_currency_price');
                    } elseif ($period == '1day') {
                        //推送一天行情
                        $day_kline = $symbol['kline_data'];
                        $day_kline['type'] = 'kline';
                        SendMarket::dispatch($day_kline)->onQueue('kline.all');
                        SendMarket::dispatch($symbol['kline_data'])->onQueue('kline.1day');
                        //存入数据库
//                        file_put_contents('/tmp/kineline.log' . $symbol['kline_data']['legal_id'] . $symbol['kline_data']['currency_id'] . '.txt', json_encode($symbol['kline_data']));
                        CurrencyQuotation::getInstance($symbol['kline_data']['legal_id'], $symbol['kline_data']['currency_id'])
                            ->updateData([
                                'change' => $symbol['kline_data']['change'],
                                'now_price' => $symbol['kline_data']['close'],
                                'volume' => $symbol['kline_data']['volume'],
                            ]);
                    } else {
                        continue;
                    }
                }
            }
        }
    }

    //订阅盘口数据
    protected function subscribeMarketDepth($con)
    {
        $currency_match = CurrencyMatch::getHuobiMatchs();
        foreach ($currency_match as $key => $value) {
            $param = [
                'symbol' => $value->match_name,
                'type' => 'step0',
            ];
            $topic = $this->makeSubscribeTopic($this->topicTemplate['sub']['market_depth'], $param);
            $sub_data = json_encode([
                'sub' => $topic,
                'id' => $topic,
            ]);
            //未订阅过的才能订阅
            if (is_null($this->getSubscribed($topic))) {
                $this->setSubscribed($topic, [
                    'callback' => 'onMarketDepth',
                    'match' => $value
                ]);
//                $con->send($sub_data);
            }
        }
    }

    protected function subscribeMarketDetail($con)
    {
        $currency_match = CurrencyMatch::getHuobiMatchs();
        foreach ($currency_match as $key => $value) {
            $param = [
                'symbol' => $value->match_name,
            ];
            $topic = $this->makeSubscribeTopic($this->topicTemplate['sub']['market_detail'], $param);
            $sub_data = json_encode([
                'sub' => $topic,
                'id' => $topic,
            ]);
            //未订阅过的才能订阅
            if (is_null($this->getSubscribed($topic))) {
                $this->setSubscribed($topic, [
                    'callback' => 'onMarketDetail',
                    'match' => $value
                ]);
//                $con->send($sub_data);
            }
        }
    }

    //盘口数据回调
    public function onMarketDepth()
    {
        foreach ($this->currencys as $currency) {
            $currency_name = strtolower($currency['name'] . 'usdt');
            $key = "market.{$currency_name}.depth.step0";

            $obj = Redis::get($key);
            $data = json_decode($obj);

            if (is_null($data)) {
                continue;
            }

            $topic = $data->ch;
            $subscribed_data = $this->getSubscribed($topic);
            $currency_match = $subscribed_data['match'];
            if(!$currency_match)
            {
                continue;
            }
            krsort($data->tick->asks);
            $data->tick->asks = array_values($data->tick->asks);
            $depth_data = [
                'type' => 'market_depth',
                'symbol' => $currency_match->currency_name . '/' . $currency_match->legal_name,
                'base-currency' => $currency_match->currency_name,
                'quote-currency' => $currency_match->legal_name,
                'currency_id' => $currency_match->currency_id,
                'currency_name' => $currency_match->currency_name,
                'legal_id' => $currency_match->legal_id,
                'legal_name' => $currency_match->legal_name,
                'bids' => array_slice($data->tick->bids, 0, 10), //买入盘口
                'asks' => array_slice($data->tick->asks, count($data->tick->asks) - 10, 10), //卖出盘口
            ];
            $symbol_key = $currency_match->currency_name . '.' . $currency_match->legal_name;
            $this->marketDepthData[$symbol_key] = $depth_data;
        }
    }

    public function onMarketDetail()
    {
        foreach ($this->currencys as $currency) {
            $currency_name = strtolower($currency['name'] . 'usdt');
            $key = "market.{$currency_name}.trade.detail";

            $obj = Redis::get($key);
            $data = json_decode($obj);

            if (is_null($data)) {
                continue;
            }
            $topic = $data->ch;
            $subscribed_data = $this->getSubscribed($topic);
            $currency_match = $subscribed_data['match'];
            if(!$currency_match)
            {
                continue;
            }
            krsort($data->tick->data);

            $detail_data = [
                'type' => 'market_detail',
                'symbol' => $currency_match->currency_name . '/' . $currency_match->legal_name,
                'base-currency' => $currency_match->currency_name,
                'quote-currency' => $currency_match->legal_name,
                'currency_id' => $currency_match->currency_id,
                'currency_name' => $currency_match->currency_name,
                'legal_id' => $currency_match->legal_id,
                'legal_name' => $currency_match->legal_name,
                'data' => array_slice($data->tick->data, count($data->tick->data) - 30, 30), //卖出盘口
            ];

            $key = strtolower($currency_match->currency_name . $currency_match->legal_name);
            foreach ($detail_data['data'] as $item) {
                $key .= $item->direction;
                Redis::set($key, $item->price);
                break;
            }
            $symbol_key = $currency_match->currency_name . '.' . $currency_match->legal_name;
            $this->marketDetailData[$symbol_key] = $detail_data;
        }
    }

    /**
     * 发送盘口数据
     *
     * @return void
     */
    public function sendDepthData()
    {
        $market_depth = $this->marketDepthData;
        foreach ($market_depth as $depth_data) {
            SendMarket::dispatch($depth_data)->onQueue('market.depth');
        }
    }

    public function sendDepthAndDetail()
    {

        try {
            $currencys = CurrencyMatch::where('market_from',3)->get();
//            var_dump($currencys);
            foreach($currencys as $c)
            {
                $currency = strtolower(Currency::getNameById($c->currency_id));
                var_dump($currency);
            $currency_id = Currency::where('name',strtoupper($currency))->first();
            $rkey = "market.{$currency}usdt.kline.1min";
            $obj = json_decode(Redis::get($rkey));
            var_dump($rkey,$obj);
            if (!$obj) {
                return;
            }

            $price = $obj->tick->close;

            $bids = [];
            $asks = [];

            for ($i = 0; $i < 10; $i++) {
                $bids[] = [$price + rand(0, 9) * 0.00001, rand(50, 300)];
            }
            for ($i = 0; $i < 10; $i++) {
                $asks[] = [$price - rand(0, 9) * 0.00001, rand(50, 300)];
            }

            $depth_data = [
                'type' => 'market_depth',
                'symbol' => strtoupper($currency) . '/USDT',
                'base-currency' => $currency_id->name,
                'quote-currency' => 'USDT',
                'currency_id' => $currency_id->id,
                'currency_name' => $currency_id->name,
                'legal_id' => 3,
                'legal_name' => 'USDT',
                'bids' => $bids, //买入盘口
                'asks' => $asks, //卖出盘口
            ];;

            $infos = [];
            for ($i = 0; $i < rand(0, 30); $i++) {
                $way = rand(1, 10) % 2 === 0 ? 'buy' : 'sell';
                $obj = [
                    "amount" => rand(100, 200) * rand(1, 3) * 0.1,
                    "ts" => intval(microtime(true) * 1000), //trade time
                    "id" => time(),
                    "time" => date('H:i:s'),
                    "tradeId" => microtime(true),
                    "price" => $way === 'buy' ? $price + rand(0, 9) * 0.00001 : $price - rand(0, 9) * 0.00001,
                    "direction" => $way
                ];
                $infos[] = $obj;
            }

            $yeah = strtoupper($currency);
            $detail_data = [
                'type' => 'market_detail',
                'symbol' => $yeah . '/USDT',
                'base-currency' => $yeah,
                'quote-currency' => 'USDT',
                'currency_id' => $currency_id->id,
                'currency_name' => $yeah,
                'legal_id' => 3,
                'legal_name' => 'USDT',
                'data' => $infos, //卖出盘口
            ];;

            SendMarket::dispatch($depth_data)->onQueue('market.depth');
            SendMarket::dispatch($detail_data)->onQueue('market.detail');
            }
        } catch (\Exception $e) {

        }

    }

    public function sendDetailData()
    {
        $market_detail = $this->marketDetailData;
        foreach ($market_detail as $detail_data) {
            array_walk($detail_data['data'], function (&$v) {
                $v->time = date('H:i:s', intVal($v->ts / 1000));
            });
            SendMarket::dispatch($detail_data)->onQueue('market.detail');
        }
    }

    //取消订阅
    protected function unsubscribe()
    {
    }

    protected function onUnsubscribe()
    {
    }

    public function onMessage($con, $data)
    {
//        $data = gzdecode($data);
//        $data = json_decode($data);
//
//        if ($this->worker_id === 0) {
////            var_dump($data);
////            if(isset($data->id)){
////                var_dump($data);
////            }
//        }
//
//        if (isset($data->ping)) {
//            if ($this->worker_id === 0) {
//                echo "0终于收到了心跳回应\r\n";
//            }
//            $this->onPong($con, $data);
//        } elseif (isset($data->pong)) {
//            $this->onPing($con, $data);
//        } elseif (isset($data->id) && $this->getSubscribed($data->id) != null) {
//            $this->onSubscribe($data);
//        } elseif (isset($data->id)) {
//
//        } else {
//            if ($this->worker_id === 0) {
////                var_dump($data);
//            }
//            $this->onData($con, $data);
//        }
    }

    protected function onData($con, $data)
    {
        if (isset($data->ch)) {
            $subscribed = $this->getSubscribed($data->ch);
            if ($subscribed != null) {
                //调用回调处理
                $callback = $subscribed['callback'];
                $this->$callback($con, $data, $subscribed['match']);
            } else {
                //不在订阅中的数据
            }
        } else {
            echo '未知数据' . PHP_EOL;
//            var_dump($data);
        }
    }

    public function sendLeverHandle()
    {
//        echo date('Y-m-d H:i:s') . '定时器取价格' . PHP_EOL;
        $now = microtime(true);
        $master_start = microtime(true);
//        echo str_repeat('=', 80) . PHP_EOL;
//        echo date('Y-m-d H:i:s') . '开始发送价格到杠杆交易系统' . PHP_EOL;
//        echo '{' . PHP_EOL;
        if (!isset($this->marketKlineData['1min'])) {
            return;
        }


        $market_kiline = $this->marketKlineData['1min'];
//        file_put_contents('/tmp/marketLine',json_encode($market_kiline,256));
        foreach ($market_kiline as $key => $value) {
            $kline_data = $value['kline_data'];
            $start = microtime(true);
//            echo "\t" . date('Y-m-d H:i:s') . ' 发送' . $key . ',价格:' . $kline_data['close'] . PHP_EOL;
            $params = [
                'legal_id' => $kline_data['legal_id'],
                'legal_name' => $kline_data['legal_name'],
                'currency_id' => $kline_data['currency_id'],
                'currency_name' => $kline_data['currency_name'],
                'now_price' => $kline_data['close'],
                'now' => $now
            ];

//            $needles = Needle::where('symbol', $kline_data['symbol'])->whereBetween('itime',[strtotime('-1 min'),strtotime('+1 min')])->get();
//            foreach ($needles as $needle) {
//                $needle = $needle->toArray();
//                $needle['itime'] = strtotime($needle['itime']);
//
//                $curren = $now;
//                $next = strtotime('+1 min', intval($curren/1000));
//
////                var_dump("当前：{$curren},下一个时间戳:{$next}");
//                if ($needle['itime'] >= $curren && $needle['itime'] < $next) {
//                    $params['now_price'] = $needle['close'];
////                    if ($this->marketData['period'] === '1min') {
////                        $this->marketData['open'] = $needle['open'];
////                        $this->marketData['high'] = $needle['high'];
////                        $this->marketData['close'] = $needle['close'];
////                        $this->marketData['low'] = $needle['low'];
////                    } else {
////                        $this->marketData['open'] = $this->marketData['open'] > $needle['open'] ? $this->marketData['open'] : $needle['open'];
////                        $this->marketData['high'] = $this->marketData['high'] > $needle['high'] ? $this->marketData['high'] : $needle['high'];
////                        $this->marketData['low'] = $this->marketData['high'] < $needle['low'] ? $this->marketData['low'] : $needle['low'];
////                        $this->marketData['close'] = $this->marketData['close'] < $needle['close'] ? $this->marketData['close'] : $needle['close'];
////                    }
////                    var_dump("修改了market：" . json_encode($this->marketData));
//                }
//            }

            //价格大于0才进行任务推送
            if (bc_comp($kline_data['close'], 0) > 0) {
                LeverUpdate::dispatch($params)->onQueue('lever:update');
                //LeverPushPrice::dispatch($params)->onQueue('lever:push:price');
            }
            $end = microtime(true);
//            echo "\t" . date('Y-m-d H:i:s') . $key . '处理完成,耗时' . ($end - $start) . '秒' . PHP_EOL;
        }
        $master_end = microtime(true);
//        echo '}' . PHP_EOL;
//        echo date('Y-m-d H:i:s') . '杠杆交易系统处理完成,耗时' . ($master_end - $master_start) . '秒' . PHP_EOL;
//        echo str_repeat('=', 80) . PHP_EOL;
    }

    public function handleMicroTrade()
    {
        //$this->marketKlineData[$period][$key]['kline_data'] = $kline_data;
        $market_data = $this->marketKlineData;
        foreach ($market_data as $period => $data) {
            foreach ($data as $key => $symbol) {
//                echo '秒合约时间:' . time() . ', Symbol:' . $key . '.' . $period . '数据' . PHP_EOL;
                if ($period == '1min') {
                    //处理秒合约
                    // $match_id=$symbol['kline_data']['match_id'];
                    // $c_m=CurrencyMatch::find($match_id);
                    // if($c_m->open_microtrade == 1){
                    if(isset($symbol['kline_data']['close']))
                    {
                        HandleMicroTrade::dispatch($symbol['kline_data'])->onQueue('micro_trade:handle');
                    }
                    // }

                } else {
                    continue;
                }
            }
        }
    }


    //计算涨幅等信息
    protected function calcIncreasePair($kline_data)
    {
        $open = $kline_data['open'];
        $close = $kline_data['close'];;
        $change_value = bc_sub($close, $open);
        $change = bc_mul(bc_div($change_value, $open), 100, 2);
        return $change;
    }


    public function ping($con)
    {
        $ping = time();
//        echo '进程' . $this->worker_id . '发送ping服务器数据包,ping值:' . $ping . PHP_EOL;
        $send_data = json_encode([
            'ping' => $ping,
        ]);
        $con->send($send_data);
        // $this->pingTimer = Timer::add($this->server_time_out, function () use ($con) {
        //     $msg = '进程' . $this->worker_id . '服务器响应超时,连接关闭' . PHP_EOL;
        //     echo $msg;
        //     $this->close($msg);
        // }, [], false);
    }

}


