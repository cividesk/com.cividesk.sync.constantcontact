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

class CRM_Sync_BAO_ConstantContact {
  CONST CONSTANTCONTACT_QUEUE_TABLE_NAME = 'cividesk_sync_constantcontact';
  CONST CONSTANTCONTACT_PREFERENCES_NAME = 'ConstantContact Sync Preferences';

  /**
   * The ConstantContact handle returned by CtCt library
   *
   * @object ConstantContact
   */
  protected $_handle;

  /**
   * The ConstantContact username for all API calls
   *
   * @string
   */
  protected $_username;

  /**
   * The custom fields holding synchronization data
   *
   * @string
   */
  protected $_custom_fields;

  /**
   * Constant Contact Settings
   */
  static $cCParams  = array('constantcontact_username', 'constantcontact_usertoken', 'constantcontact_apikey', 'constantcontact_timeout');

  function __construct($oauth_username, $oauth_key, $oauth_token) {
    /**
     * CiviCRM configuration
     */
    $this->_custom_fields = $this->get_or_create_custom_fields();

    /**
     * ConstantContact configuration & includes
     */
    require_once 'Zend/Loader.php';
    Zend_Loader::loadClass('Zend_Gdata');
    Zend_Loader::loadClass('Zend_Gdata_ClientLogin');
    Zend_Loader::loadClass('Zend_Http_Client');
    Zend_Loader::loadClass('Zend_Gdata_Query');
    Zend_Loader::loadClass('Zend_Gdata_Feed');
    Zend_Loader::loadClass('Zend_Oauth');
    Zend_Loader::loadClass('Zend_Oauth_Consumer');

    // prepare OAuth login and set protocol version to 3.0
    $oauthOptions = array(
      'requestScheme' => Zend_Oauth::REQUEST_SCHEME_HEADER,
      'version' => '1.0',
      'signatureMethod' => 'HMAC-SHA1',
      'consumerKey' => $oauth_key,
      'consumerSecret' => $oauth_secret,
    );
    $consumer = new Zend_Oauth_Consumer($oauthOptions);
    $token = new Zend_Oauth_Token_Access();
    $client = $token->getHttpClient($oauthOptions,null);
    $client->setMethod(Zend_Http_Client::GET);
    $client->setHeaders('If-Match: *'); // needed for update and delete operations
    $gdata = new Zend_Gdata($client);
    $gdata->setMajorProtocolVersion(3);
    $this->_handle = $gdata;
    $this->_requester = $oauth_email;
  }

  static function get_or_create_custom_fields() {
    $fields = array(
      'ctct_ind' => array(
        'title' => 'ConstantContact Sync',
        'extends' => 'Individual',
        'is_active' => 1,
        'weight' => 100,
        'fields' => array(
          'ctct_id' => array(
            'label' => 'ConstantContact Id',
            'data_type' => 'String',
            'html_type' => 'Text',
            'text_length' => 8,
            'is_active' => 1,
            'is_view' => 1,
          ),
          'last_sync' => array(
            'label' => 'Last Synchronized',
            'data_type' => 'Date',
            'html_type' => 'Select Date',
            'is_active' => 1,
            'is_searchable' => 1,
            'is_view' => 1,
          ),
          'checksum' => array(
            'label' => 'Sync Checksum',
            'data_type' => 'String',
            'html_type' => 'Text',
            'text_length' => 40,
            'is_active' => 1,
            'is_view' => 1,
          ),
        ),
      ),
      'ctct_grp' => array(
        'title' => 'ConstantContact Sync',
        'extends' => 'Group',
        'is_active' => 1,
        'weight' => 100,
        'fields' => array(
          'ctct_id' => array(
            'label' => 'ConstantContact List Id',
            'data_type' => 'String',
            'html_type' => 'Text',
            'text_length' => 8,
            'is_active' => 1,
            'is_view' => 1,
          ),
        ),
      ),
    );
    foreach ( $fields as $group_id => $group ) {
      // Get custom group
      $params = array(
        'version' => 3,
        'title' => $group['title'],
        'extends' => $group['extends'],
      );
      $result = civicrm_api( 'CustomGroup', 'get', $params );
      if ( $result['count'] == 0 ) {
        // Non-existent, let's create the custom group
        $params += $group;
        $result = civicrm_api( 'CustomGroup', 'create', $params );
        if ( $result['is_error'] )
          return civicrm_api3_create_error( 'Could not create the custom fields set' );
        $params = reset($result['values']);
        $params['version'] = 3;
        $params['title'] = 'Cividesk sync for ConstantContact';
        $result = civicrm_api('CustomGroup', 'create', $params);
      }
      $fields[$group_id] = array_merge( reset( $result['values'] ), $group );
      foreach ($group['fields'] as $field_id => $field ) {
        // Get custom field
        $params = array(
          'version' => 3,
          'custom_group_id' => $fields[$group_id]['id'],
          'label' => $field['label'],
        );
        $result = civicrm_api( 'CustomField','get',$params );
        if ( $result['count'] == 0 ) {
          // Non-existent, let's create the custom fields
          $params += $field;
          $result = civicrm_api( 'CustomField','create',$params );
          if ( $result['is_error'] )
            return civicrm_api3_create_error( 'Could not create a custom field' );
        }
        $fields[$group_id]['fields'][$field_id] = array_merge( reset( $result['values'] ), $field );
      }
    }
    return $fields;
  }

  static function get_scheduledJob() {
    // The Job API is not implemented yet in 4.2, so use DAO
    $dao = new CRM_Core_DAO_Job();
    $dao->domain_id  = CRM_Core_Config::domainID();
    $dao->api_prefix = 'civicrm_api3';
    $dao->api_entity = 'Job';
    $dao->api_action = 'googleapps_sync';
    if (!$dao->find(true)) {
      $dao->name = 'Cividesk sync for ConstantContact';
      $dao->description = 'Synchronizes identified CiviCRM groups with ConstantContact. You can adjust the \'max_processed\' parameter to control how many contacts are processed each run (default: 50).';
      $dao->run_frequency = 'Hourly';
      $dao->is_active = 1;
      $dao->insert();
    }
    return $dao;
  }

  static function getSettings() {
    return CRM_Core_BAO_Setting::getItem(CRM_Sync_BAO_ConstantContact::CONSTANTCONTACT_PREFERENCES_NAME);
  }

  static function setSetting($value, $key) {
    return CRM_Core_BAO_Setting::setItem($value, CRM_Sync_BAO_ConstantContact::CONSTANTCONTACT_PREFERENCES_NAME, $key);
  }

  function call($object, $op, $params = array()) {
    // Check authentication and scope
    if (empty($this->_handle) || empty($this->_scope)) {
      throw new Exception('You need to initialize the scope before calling Google Apps.');
    }

    // Check sanity of arguments
    if (!in_array($object, array('contact', 'group'))) {
      throw new Exception('Unknow object type.');
    }
    if (!in_array($op, array('get', 'create', 'update', 'delete'))) {
      throw new Exception('Unknow operation type.');
    }

    // Calculate base URL for request
    $url = 'https://www.google.com/m8/feeds/' . $object . 's/';
    if (strpos('@', $this->_scope) !== false) {
      $url .= 'default/';
    } else {
      $url .= $this->_scope . '/';
    }
    $url .= ($op == 'delete' ? 'base' : 'full');
    $url .= ($op == 'update' || $op == 'delete' ? '/'.CRM_Utils_Array::value('google_contact_id', $params) : '');

    // Create Query object
    $query = new Zend_Gdata_Query( $url );
    $query->setParam('xoauth_requestor_id', $this->_requester);

    // Perform operation with Google, then CiviCRM
    $now = date('YmdHis'); // do NOT use MySQL NOW() as this is not user-timezoned
    switch( $op ) {
      case 'get':
        $result = $this->_handle->getFeed($query);
        break;
      case 'create':
        $xml = $this->_objectXML($object, $params);
        if ($result = $this->_handle->insertEntry($xml, $query->getQueryUrl())) {
          // Extract Google Contact Id & save in CiviCRM
          preg_match('/(.*)\/(.*)/', $result->id, $matches);
          $result = $matches[2]; // return the Google_id
          $query = "
INSERT INTO `{$this->_custom_group['table_name']}`
  (entity_id,{$this->_custom_fields['google_id']['column_name']},{$this->_custom_fields['last_sync']['column_name']})
VALUES
  ($params[civicrm_contact_id],'$matches[2]','$now')";
          CRM_Core_DAO::executeQuery($query);
        }
        break;
      case 'update':
        $xml = $this->_objectXML($object, $params);
        if ($result = $this->_handle->updateEntry($xml, $query->getQueryUrl())) {
          $query = "
UPDATE `{$this->_custom_group['table_name']}`
   SET {$this->_custom_fields['last_sync']['column_name']} = '$now'
 WHERE {$this->_custom_fields['google_id']['column_name']} = '$params[google_contact_id]'";
          CRM_Core_DAO::executeQuery($query);
        }
        break;
      case 'delete':
        if ($result = $this->_handle->delete($query->getQueryUrl())) {
          $query = "
DELETE FROM `{$this->_custom_group['table_name']}`
 WHERE entity_id = $params[civicrm_contact_id]";
          CRM_Core_DAO::executeQuery($query);
        }
        break;
    }
    return $result;
  }

  function _civi2ctct( $contact, $ctct_list ) {
    global $ctctCredentials;

    $ctct = new Contact();
    $ctct->emailAddress = substr($contact['email'], 0, 80);
    $ctct->emailType    = ($contact['preferred_mail_format']=='Both' ? 'HTML' : $contact['preferred_mail_format']);
    $ctct->firstName    = mb_substr($contact['first_name'], 0, 50);
    $ctct->lastName     = mb_substr($contact['last_name'], 0, 50);
    $ctct->companyName  = mb_substr($contact['organization_name'], 0, 50);
    $ctct->lists = array();
    if ($contact['group_list']) {
      foreach( explode( ',', $contact['group_list'] ) as $civi_id) {
        $ctct->lists[] = $ctct_list[$civi_id];
      }
    }
    return $ctct;
  }
  static function getCCParams() {
    return self::$cCParams;
  }
}