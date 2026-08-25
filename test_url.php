<?php
$fieldsParam = '{"tc_no":true,"baba_adi":true,"anne_adi":true,"defin_tarihi":true,"dogum_tarihi":true,"il_ilce":true,"yakin_bilgisi":true,"sol_imza":true,"sag_imza":true,"sag_imza_tipi":"yakin"}';
$fields = json_decode($fieldsParam, true) ?: [];
$sagImzaTipi = $fields['sag_imza_tipi'] ?? 'mudur';
echo $sagImzaTipi;
