<?php
require 'api/config.php';
$pdo = getDbConnection();
$r = ['id'=>1, 'yakin_ad_soyad'=>'Test Yakin', 'defin_tarihi'=>'2023-01-01'];
$fields = ['sag_imza_tipi' => 'yakin', 'sag_imza' => true];
require 'api/index.php'; // wait, this will execute the routing if not careful.
