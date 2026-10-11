<?php
// Isolated protocol probe against actual old/new settings and export methods.
define('ABSPATH','/tmp/');
$root=$argv[1];
function absint($v){return abs((int)$v);} function sanitize_text_field($v){return (string)$v;}
function sanitize_key($v){return (string)$v;} function wp_strip_all_tags($v){return strip_tags($v);}
function wp_timezone(){return new DateTimeZone('Asia/Tehran');}
function home_url($v){return 'https://sp.example.invalid/';}
function wp_parse_args($a,$d){return array_merge($d,$a);}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$a=false){$GLOBALS['options'][$k]=$v;}
function get_role($v){return null;} function get_users($a){return [];}
function wc_get_order_statuses(){return ['wc-processing'=>'Processing','wc-printed-send'=>'Printed'];}
function is_wp_error($v){return $v instanceof WP_Error;}
function wc_get_orders($a){$GLOBALS['query']=$a;return (object)['orders'=>[],'total'=>0,'max_num_pages'=>0];}
class WP_Error{}
class WC_DateTime extends DateTime{}
class WC_Order{function __construct(public $id){} function get_type(){return 'shop_order';} function get_date_created(){return new WC_DateTime('2026-09-15T12:00:00+00:00');}}
function wc_get_order($id){return new WC_Order($id);}
class WP_REST_Request{function __construct(public $data){}function get_json_params(){return $this->data;}}
class Company_Order_Sync_Order_Serializer{function serialize($o){return ['id'=>$o->id,'status'=>'processing'];}}
class Company_Order_Sync_Order_Mapper{}
require $root.'/includes/class-settings.php';
require $root.'/includes/class-snapshot-sync.php';
require $root.'/includes/class-status-repair.php';
$original=['mode'=>'store','store_id'=>'site2','central_url'=>'https://central.example.invalid','store_outbound_secret'=>str_repeat('x',32),'store_inbound_secret'=>str_repeat('y',32),'signature_ttl'=>300];
$GLOBALS['options'][Company_Order_Sync_Settings::OPTION]=$original;
$settings=Company_Order_Sync_Settings::all();
foreach($original as $k=>$v){if($settings[$k]!==$v)throw new Exception('Setting changed: '.$k);}
$repair=new Company_Order_Sync_Status_Repair();
$counts=[];
foreach([1,20,21,100] as $n){$r=$repair->export_orders_by_ids(new WP_REST_Request(['date_from'=>'2026-08-01','order_ids'=>range(1,$n)]),['request_id'=>'probe']);$counts[$n]=count($r['orders']);}
$r=$repair->export_status_snapshot(new WP_REST_Request(['date_from'=>'2026-09-13','boundary_date'=>'2026-08-06','date_to'=>'2026-09-20 12:00:00','per_page'=>500,'page'=>1,'include_directory'=>true,'modified_from'=>'2026-09-20 08:00:00']),['request_id'=>'probe']);
$out=['preserved_settings'=>true,'batch_returned'=>$counts,'stored_boundary'=>Company_Order_Sync_Settings::source_min_date(),'directory_keys'=>isset($r['roles'],$r['users']),'modified_filter'=>isset($GLOBALS['query']['date_modified']),'index_limit'=>$GLOBALS['query']['limit']];
if(str_contains($root,'public_html')){
 if($counts!==[1=>1,20=>20,21=>21,100=>100]||$out['stored_boundary']!=='2026-08-06'||!$out['directory_keys']||!$out['modified_filter'])throw new Exception('New protocol regression');
}
echo json_encode($out,JSON_PRETTY_PRINT)."\n";
