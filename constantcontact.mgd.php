<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array(
  0 => array(
    'name' => 'Constant Contact Sync',
    'entity' => 'Job',
    'params' => array(
    	'version' => 3,
    	'api_entity' => "job",
      'sequential' => 1,
      'run_frequency' => "Daily",
      'name' => "Constant Contact Sync",
      'api_action' => "constant_contact_sync",
    ),
  ),
);
