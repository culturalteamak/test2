<?php

$data = '123456';

$original = file_get_contents('rsa_private_key.pem'); // 获取私钥文件中的数据
$privateKey = openssl_pkey_get_private($original);//这个函数可用来判断私钥是否是可用的，可用，返回资源，私钥可用file_get_content从文件中获取
// $data为需要加密的数据（字符串数组都可以，数组用json_encode转化下），$encrypted接收加密后数据，$privateKey为私钥密钥，base64_encode是为了方便网络传输
openssl_private_encrypt($data,$encrypted,$privateKey);
$edata = base64_encode($encrypted);
var_dump($edata);
var_dump($privateKey);

$public_key = 'rsa_public_key.pub';
$pubkey = openssl_pkey_get_public($public_key);
$res = openssl_public_decrypt(base64_decode($edata), $decrypted,$pubkey);
var_dump($res,$decrypted);

die;
echo strtotime('+1 month');
die;
echo strtotime('+1 min');
die;
require __DIR__ . '/smtp.php';

$smtp = new Smtp('103.98.112.66', 8080, true, 'service@pcmcoinb.com', 'NxFpJyA5ipEvJjEK7ecoAw');//这里面的一个true是表示使用身份验证,否则不使用身份验证.
$smtp->debug = true;//是否显示发送的调试信息
$state = $smtp->sendmail('dab1117@163.com', 'service@pcmcoinb.com', '测试邮件', '这是一封测试邮件', 'HTML');
