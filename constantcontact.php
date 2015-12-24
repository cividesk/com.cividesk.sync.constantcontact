<?php
/*
 +--------------------------------------------------------------------------+
 | Copyright IT Bliss LLC (c) 2012-2013                                     |
 +--------------------------------------------------------------------------+
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU Affero General Public License as published |
 | by the Free Software Foundation, either version 3 of the License, or     |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
 | GNU Affero General Public License for more details.                      |
 |                                                                          |
 | You should have received a copy of the GNU Affero General Public License |
 | along with this program.  If not, see <http://www.gnu.org/licenses/>.    |
 +--------------------------------------------------------------------------+
*/
require_once 'constantcontact.civix.php';
require_once 'packages/Ctct/autoload.php';
use Ctct\ConstantContact;
use Ctct\Components\Contacts\Contact;
use Ctct\Components\Contacts\ContactList;
use Ctct\Components\Contacts\EmailAddress;
use Ctct\Exceptions\CtctException;

function constantcontact_civicrm_buildForm($formName, &$form){
  if($formName == "CRM_Group_Form_Edit"){
    $settings = CRM_Sync_BAO_ConstantContact::getSettings();
    $cc_usertoken = CRM_Utils_Array::value('constantcontact_usertoken', $settings, false);
    $cc_apikey    = CRM_Utils_Array::value('constantcontact_apikey',    $settings, false);
    if($cc_usertoken != "" && $cc_apikey != ""){
      foreach($form->_groupTree as $group){
        if($group['title'] == "ConstantContact sync (by cividesk)" ){
          foreach($group['fields'] as $field){
            if($field['label'] == "ConstantContact List Id"){
              $cc = new ConstantContact($cc_apikey);
              try {
                $result = $cc->getLists($cc_usertoken);
                $options = array();
                foreach($result as $value){
                  $options[$value->id] = $value->name;
                }
                if (array_key_exists( $field['element_name'], $form->_elementIndex)) {
                  $form->removeElement($field['element_name']);
                }
                $form->add('select', $field['element_name'], ts('Constant Contact Sync Id'), array('' => ts('- select -')) + $options);
              } catch (CtctException $ex) {
                foreach ($ex->getErrors() as $error) {
                  CRM_Core_Session::setStatus($error['error_message'], ts('Failed.'), 'error');
                }
              }
            } 
          }
        }
      }
    }
  }
}

function constantcontact_civicrm_postProcess($formName, &$form){
  if($formName == "CRM_Group_Form_Edit"){
    $result = civicrm_api3('Job', 'execute', array(
      'sequential' => 1,
      'api_action' => "constant_contact_sync",
    ));
  }
}

/**
 * Implementation of hook_civicrm_config
 */
function constantcontact_civicrm_config(&$config) {
  _constantcontact_civix_civicrm_config($config);
  // Include path is not working if relying only on the above function
  // seems to be a side-effect of CRM_Core_Smarty::singleton(); also calling config hook
  $extRoot = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
  set_include_path($extRoot . PATH_SEPARATOR . get_include_path());
  if (is_dir($extRoot . 'packages')) {
    set_include_path($extRoot . 'packages' . PATH_SEPARATOR . get_include_path());
  }
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function constantcontact_civicrm_xmlMenu(&$files) {
  _constantcontact_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function constantcontact_civicrm_install() {
  // required to define the CONST below
/*  constantcontact_civicrm_config(CRM_Core_Config::singleton());
  require_once 'CRM/Sync/BAO/ConstantContact.php';
  // Create sync queue table if not exists
  $query = "
    CREATE TABLE IF NOT EXISTS `" . CRM_Sync_BAO_ConstantContact::CONSTANTCONTACT_QUEUE_TABLE_NAME . "` (
          `id` int(10) NOT NULL AUTO_INCREMENT,
          `civicrm_contact_id` int(10) NOT NULL,
          `google_contact_id` varchar(32) DEFAULT NULL
    PRIMARY KEY (`id`),
      KEY `civicrm_contact_id` (`civicrm_contact_id`),
      KEY `google_contact_id` (`google_contact_id`)
    ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
  CRM_Core_DAO::executeQuery($query);
*/  return _constantcontact_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function constantcontact_civicrm_uninstall() {
  // required to define the CONST below
  constantcontact_civicrm_config(CRM_Core_Config::singleton());
  require_once 'CRM/Sync/BAO/ConstantContact.php';
  // Drop sync queue table
  $query = "DROP TABLE IF EXISTS `" . CRM_Sync_BAO_ConstantContact::CONSTANTCONTACT_QUEUE_TABLE_NAME . "`;";
  CRM_Core_DAO::executeQuery($query);
  // Delete all settings
  // following line causes error because there is no deleteItem function
  //CRM_Core_BAO_Setting::deleteItem(CRM_Sync_BAO_ConstantContact::CONSTANTCONTACT_PREFERENCES_NAME);
  return _constantcontact_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function constantcontact_civicrm_enable() {
  return _constantcontact_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function constantcontact_civicrm_disable() {
  return _constantcontact_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function constantcontact_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _constantcontact_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function constantcontact_civicrm_managed(&$entities) {
  return _constantcontact_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_navigationMenu
 */
function constantcontact_civicrm_navigationMenu( &$params ) {
  constantcontact_civicrm_config(CRM_Core_Config::singleton());
  // Add menu entry for extension administration page
  _constantcontact_civix_insert_navigation_menu($params, 'Administer/System Settings', array(
    'name'       => 'Cividesk sync for ConstantContact',
    'url'        => 'civicrm/admin/sync/constantcontact',
    'permission' => 'administer CiviCRM',
  ));
  
}
