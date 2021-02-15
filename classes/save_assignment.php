<?php

//defined('MOODLE_INTERNAL') || die;

require_once(dirname(__FILE__). '/../../../config.php');
require_once($CFG->dirroot.'/mod/arete/classes/filemanager.php');
require_once($CFG->dirroot.'/mod/arete/classes/assignmanager.php');

global $DB;

$returnurl = filter_input(INPUT_POST, 'returnurl' );
$areteid = filter_input(INPUT_POST, 'moduleid' );
$arlemid = filter_input(INPUT_POST, 'arlem' );

$update_record = new stdClass();
$update_record-> id = $DB->get_field('arete_arlem', 'id', array('areteid' => $areteid ));
$update_record-> areteid = $areteid;
$update_record-> arlemid =  $arlemid;
$update_record->timecreated = time();

//         update the record with this id. $data comes from update_form
$DB->update_record('arete_arlem', $update_record);


//deleted the arlems which has checkbox checked
if(isset($_POST['deletearlem'])){
   foreach ($_POST['deletearlem'] as $arlem) {
       
        list($id ,$filename, $itemid) = explode('(,)', $arlem);

        if(is_arlem_exist($id)){
                deletePluginArlem($filename, $itemid);
        }

    } 
}


//redirect
redirect($returnurl, array());