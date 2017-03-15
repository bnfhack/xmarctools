<?php
ini_set('display_errors', '1');
error_reporting(-1);
return array(
  "srcglob" => dirname(__FILE__)."/xmarc/*.xml", // dossier de notices interXmarc
  "sqlite" => "notices.sqlite", // base sqlite à créer
);
?>
