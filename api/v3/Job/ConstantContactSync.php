<?php
// ConstantContact sync function
// TODO:
// - get the list of DoNotEMail from CtCt and apply to CiviCRM
// - better manage the ConstantContact statuses:
// UNCONFIRMED	The contact has been added by the Constant Contact account; the account cannot send the contact email messages until they contact confirm their subscription, at which time the status changes to ACTIVE.
// ACTIVE	The contact subscribed them self to an email list, or they confirmed their subscription after being added by the account. They can be sent email campaigns.
// OPTOUT	The contact has unsubscribed them self and is on the system Do Not Mail list; the Contant Contact account cannot add them to any contact list. See Opting Contacts Back in to learn how a contact can change their status from OPTOUT to ACTIVE.
// REMOVED	The account has removed the contact from all contact lists; the account can add them to a contact list because the optout action_by= ACTION_BY_OWNER
// NON_SUBSCRIBER	The contact has registered for an account's event, but has not subscribed to any email lists.
// VISITOR	A contact with this status has "liked" a social campaign page, but has not subscribed to a contact list.

require_once 'packages/Ctct/autoload.php';
use Ctct\ConstantContact;
use Ctct\Components\Contacts\Contact;
use Ctct\Components\Contacts\ContactList;
use Ctct\Components\Contacts\EmailAddress;
use Ctct\Exceptions\CtctException;

function civicrm_api3_job_constant_contact_sync( $sync_params )
{
    $custom_fields = array(
        'ctct_ind' => array(
            'title' => 'ConstantContact sync (by cividesk)',
            'extends' => 'Individual',
            'is_active' => 1,
            'weight' => 100,
            'fields' => array(
                'ctct_id' => array(
                    'label' => 'ConstantContact Id',
                    'data_type' => 'String',
                    'html_type' => 'Text',
                    'text_length' => 16,
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
            'title' => 'ConstantContact sync (by cividesk)',
            'extends' => 'Group',
            'is_active' => 1,
            'weight' => 100,
            'fields' => array(
                'ctct_id' => array(
                    'label' => 'ConstantContact List Id',
                    'data_type' => 'String',
                    'html_type' => 'Text',
                    'text_length' => 16,
                    'is_active' => 1,
                    'is_view' => 1,
                    ),
                ),
            ),
        );

    /**
     * CiviCRM configuration & includes
     */
    require_once 'api/api.php';
    $custom_fields = get_or_create_custom_fields($custom_fields);
    $sync_groups = get_sync_groups($custom_fields);

    // No need to continue if nothing to sync
    if (empty($sync_groups)) {
      return civicrm_api3_create_success( array('No groups defined for synching') );
    }

    // build plain and smart groups lists, refresh cache for all smart groups
    $plain_group_list = $smart_group_list = array();
    foreach ( $sync_groups as $gid => $group ) {
      if (CRM_Utils_Array::value('saved_search_id', $group)) {
        $smart_group_list[] = $gid;
        $params = array(array('group', 'IN', array($gid => 1), 0, 0));
        $returnProperties = array( 'contact_id' );
        // the below call update the cache table as a byproduct of the query
        CRM_Contact_BAO_Query::apiQuery($params, $returnProperties, null, null, 0, 0, false);
      } else {
        $plain_group_list[] = $gid;
      }
    }
    // convert from array to SQL list
    $plain_group_list = implode(',', $plain_group_list);
    $smart_group_list = implode(',', $smart_group_list);

    $settings = CRM_Sync_BAO_ConstantContact::getSettings();
    $last_sync    = CRM_Utils_Array::value('last_sync',                 $settings, '2000-01-01 00:00:00');
    $cc_username  = CRM_Utils_Array::value('constantcontact_username',  $settings, false);
    $cc_usertoken = CRM_Utils_Array::value('constantcontact_usertoken', $settings, false);
    $cc_apikey    = CRM_Utils_Array::value('constantcontact_apikey',    $settings, false);
    $cc_timeout   = CRM_Utils_Array::value('constantcontact_timeout',   $settings, 0);
    if ( $cc_username ) {
      define( 'CTCT_USERNAME',  $cc_username );
    }
    if ( $cc_usertoken ) {
      define( 'CTCT_USERTOKEN',  $cc_usertoken );
    }
    if ( $cc_apikey ) {
        define( 'CTCT_APIKEY',  $cc_apikey );
    }
    if ( $cc_timeout ) {
        define( 'CTCT_TIMEOUT',  $cc_timeout );
    }
    // now create query for selecting all contacts that MIGHT need to be synchronized
    $subquery = "SELECT contact_id, MAX(modified_date) as modified_date FROM (";
    // 1- add all contacts modified since last sync
    $subquery .= "
        SELECT entity_id as contact_id, modified_date
        FROM   civicrm_log
        WHERE  modified_date > '$last_sync' AND entity_table = 'civicrm_contact'";
    // 2- add all contacts added/removed from a synched group since last sync
    if ($plain_group_list) {
      $subquery .= " UNION
          SELECT contact_id, `date` as modified_date
          FROM   civicrm_subscription_history
          WHERE  `date` > '$last_sync' AND group_id IN ($plain_group_list)";
    }
    // 3- add all contacts that are part of a smart groups
    if ($smart_group_list) {
      $subquery .= " UNION
          SELECT contact_id, NOW() as modified_date
          FROM   civicrm_group_contact_cache
          WHERE  group_id IN ($smart_group_list)";
    }
    // Group and Order (1 record per contact Id, older to newest)
    $subquery .= ") u GROUP BY contact_id ORDER BY modified_date ASC";
    // extract contacts that NEED to be synchronized from the above list
    $querySync = "
        SELECT
            s.contact_id, s.modified_date, ca.first_name, ca.last_name, ca.is_deleted,
            cb.organization_name, e.email, ca.preferred_mail_format, ca.is_opt_out, gl.group_list,
            v.{$custom_fields['ctct_ind']['fields']['ctct_id']['column_name']} as ctct_id,
            v.{$custom_fields['ctct_ind']['fields']['last_sync']['column_name']} as last_sync,
            v.{$custom_fields['ctct_ind']['fields']['checksum']['column_name']} as checksum,            
            MD5(CONCAT_WS('',ca.first_name,ca.last_name,cb.organization_name,e.email,ca.preferred_mail_format,ca.is_opt_out,gl.group_list,ca.is_deleted)) as new_checksum
        FROM
            ($subquery) s
            LEFT JOIN civicrm_email e ON e.contact_id = s.contact_id AND e.is_primary = 1
            LEFT JOIN (SELECT contact_id, CAST(GROUP_CONCAT(group_id)as CHAR) as group_list FROM ("
                . ($plain_group_list ? "SELECT contact_id, group_id FROM civicrm_group_contact
                    WHERE group_id IN ($plain_group_list) AND status = 'Added'" : '')
                . ($plain_group_list && $smart_group_list ? ' UNION ' : '' )
                . ($smart_group_list ? "SELECT contact_id, group_id FROM civicrm_group_contact_cache
                    WHERE group_id IN ($smart_group_list)" : '') . "
                ) u2
                GROUP BY contact_id ORDER BY NULL) gl ON gl.contact_id = s.contact_id
            LEFT JOIN {$custom_fields['ctct_ind']['table_name']} v
                ON v.entity_id = s.contact_id
            LEFT JOIN civicrm_contact ca
                ON ca.id = s.contact_id
            LEFT JOIN civicrm_contact cb
                ON cb.id = ca.employer_id
        WHERE
            ca.contact_type = 'Individual'
            -- If both group_list IS NULL and ctct_id IS NULL it means contact was never synch'ed and does not need to be
            AND (gl.group_list IS NOT NULL OR v.{$custom_fields['ctct_ind']['fields']['ctct_id']['column_name']} IS NOT NULL)
        HAVING  -- MySQL cannot use column aliases in WHERE clause, only in HAVING clause
            checksum IS NULL OR checksum != new_checksum
        ORDER BY
            s.modified_date ASC";
    $dao = CRM_Core_DAO::executeQuery( $querySync );
    // Check if all the constants are defined
    if (!defined('CTCT_APIKEY') || !defined('CTCT_USERNAME') || !defined('CTCT_USERTOKEN') || !defined('CTCT_TIMEOUT')) {
      return civicrm_api3_create_error('Missing required CTCT constants in civicrm.settings.php');
    }
    $ConstantContact = new ConstantContact(CTCT_APIKEY);

    $messages  = array();
    $processed = array('total' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0);
    while ($dao->fetch() && ($processed['total'] < 100)) {
      $result = false; // Will contain resulting CtCt Contact if sync is successful
      $contact = $dao->toArray();
      // Saved last_sync date - DO NOT CHANGE the 'ORDER BY s.modified_date ASC' above
      $last_sync = CRM_Utils_Array::value('modified_date', $contact, $last_sync);
      try {
        if (empty($contact['email']) || empty($contact['group_list']) || $contact['is_deleted']) {
            // contact is not subscribed to any synched lists
            if (empty($contact['ctct_id'])) // contact is not synched right now
                continue;
            else { // contact needs to be unsubscribed or deleted in Constant Contact
                $NewContact = civi2ctct( $contact, $sync_groups );
                usleep(CTCT_TIMEOUT);
                $OldContact = $ConstantContact->getContact(CTCT_USERTOKEN, $contact['ctct_id']);
                if ($OldContact->id != $contact['ctct_id']) {
                    // could not find contact to delete in CtCt
                    $result = new Contact(); // So id is NULL for saving back into CiviCRM
                } elseif ($OldContact->status == 'OPTOUT') {
                    $result = $OldContact;
                } else {
                  // merge contact and see if subscribed to any CtCt-only lists
                    $NewContact = merge_contact( $NewContact, $OldContact );
                    if (empty($NewContact->lists)) { // delete in CtCt
                        usleep(CTCT_TIMEOUT);
                        if ($ConstantContact->deleteContact(CTCT_USERTOKEN, $NewContact)) {
                          $processed['deleted'] ++;
                          $result = new Contact(); // So id is NULL for saving back into CiviCRM
                        }
                    } else {                         // update in CtCt
                        usleep(CTCT_TIMEOUT);
                        if ($result = $ConstantContact->updateContact(CTCT_USERTOKEN, $NewContact)) {
                            $processed['updated'] ++;
                        }
                    }
                }
            }
        } else {
            // contact is subscribed to at least one synched list
            // so we will need to be created or modified in CtCt
            $NewContact = civi2ctct( $contact, $sync_groups );
            if (empty($contact['ctct_id'])) {
                // contact was never synch'ed with CtCt
                usleep(CTCT_TIMEOUT);
                $Results = $ConstantContact->getContactByEmail(CTCT_USERTOKEN, $contact['email']);
                if ($OldContact = reset($Results->results)) {
                    if ($OldContact->status == 'OPTOUT') {
                      $result = $OldContact;
                    } else {
                      $NewContact = merge_contact( $NewContact, $OldContact );
                      usleep(CTCT_TIMEOUT);
                      if ($result = $ConstantContact->updateContact(CTCT_USERTOKEN, $NewContact)) {
                          $processed['updated'] ++;
                      }
                    }
                } else {
                    // contact really needs to be created in CtCt
                    usleep(CTCT_TIMEOUT);
                    if ($result = $ConstantContact->addContact(CTCT_USERTOKEN, $NewContact))
                        $processed['created'] ++;
                }
            } else {
                // contact needs to be updated in CtCt
                // we are searching with CtCt_Id rather than email as this will allow
                // to change the email address in Civi and have this port over to CtCt
                usleep(CTCT_TIMEOUT);
                $OldContact = $ConstantContact->getContact(CTCT_USERTOKEN, $contact['ctct_id']);
                if ($OldContact->id != $contact['ctct_id']) {
                    // cannot locate contact in CtCt -> recreate
                    usleep(CTCT_TIMEOUT);
                    if ($result = $ConstantContact->addContact(CTCT_USERTOKEN, $NewContact))
                        $processed['created'] ++;
                } elseif ($OldContact->status == 'OPTOUT') {
                    $result = $OldContact;
                } else {
                    // merge contact with the information in CtCt
                    $NewContact = merge_contact( $NewContact, $OldContact );
                    usleep(CTCT_TIMEOUT);
                    if ($result = $ConstantContact->updateContact(CTCT_USERTOKEN, $NewContact)) {
                        $processed['updated'] ++;
                    }
                }
            }
        }
        if ($result) {
            // set it here because we want to catch ALL sync actions
            if (is_a($result, 'CtCt\Components\Contacts\Contact')) {
              $contact['ctct_id'] = $result->id; // will also be null if CtCt contact has been deleted
              if ($result->status == 'OPTOUT') {
                $query = "UPDATE civicrm_contact SET is_opt_out = 1 WHERE id = {$contact['contact_id']}";
                CRM_Core_DAO::executeQuery( $query );
              }
            }
            $contact['last_sync'] = date( "Y-m-d H:i:s");
            $contact['checksum']  = $contact['new_checksum'];
            // we did something, so now we need to update Civi
            $query = "REPLACE INTO {$custom_fields['ctct_ind']['table_name']} (
                    entity_id,
                    {$custom_fields['ctct_ind']['fields']['ctct_id']['column_name']},
                    {$custom_fields['ctct_ind']['fields']['last_sync']['column_name']},
                    {$custom_fields['ctct_ind']['fields']['checksum']['column_name']}
                ) VALUES (
                    {$contact['contact_id']},
                    '{$contact['ctct_id']}',
                    '{$contact['last_sync']}',
                    '{$contact['checksum']}'
                )";
                CRM_Core_DAO::executeQuery( $query );
                // don't forget the pat on the shoulder ...
                $processed['total'] ++;
        }
      } catch (Exception $e) {
        $messages[] = $e->getMessage();
        // Still must be counted as processed to limit processing time
        $processed['total'] ++;
      }
    }

    // Change frequency of job until the job is finished
    $job = civicrm_api3('Job', 'getsingle', array(
      'sequential' => 1,
      'api_action' => "constant_contact_sync",
    ));
    //Run query again to make sure no contacts are left over
    $dao = CRM_Core_DAO::executeQuery( $querySync );
    if( $dao->fetch() ){
      //if so set the scheudled job to run always
      $result = civicrm_api3('Job', 'create', array(
        'sequential' => 1,
        'api_action' => "constant_contact_sync",
        'id' => $job['id'],
        'run_frequency' => "Always"
      ));
    } else {
      // Only set last_sync date if the job is finished
      // save last_sync date
      CRM_Sync_BAO_ConstantContact::setSetting($last_sync, 'last_sync');
      //if not set scheudled job to the next highest frequency
      switch ( $job['run_frequency'] ) {
        case "Always":
       	  $result = civicrm_api3('Job', 'create', array(
            'sequential' => 1,
            'api_action' => "constant_contact_sync",
            'id' => $job['id'],
            'run_frequency' => "Hourly"
          ));
          break;
       case "Hourly":
       	  $result = civicrm_api3('Job', 'create', array(
            'sequential' => 1,
            'api_action' => "constant_contact_sync",
            'id' => $job['id'],
            'run_frequency' => "Daily"
          ));
          break;
      }
    }

    // all done, create summary
    if ($processed['total'] == 0) {
        $messages[] = "Nothing needed to be synchronized.";    
    } else {
        foreach( array('created', 'updated', 'deleted') as $action ) {
            $messages[] = $processed[$action] . " contact(s) $action.";
        }
    }

    return civicrm_api3_create_success( $messages );
}

function get_or_create_custom_fields( $fields ) {

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

function get_sync_groups( $custom_fields ) {

    $sync_groups = array();

    // Too complicated to get via the API ...
    $table  = $custom_fields['ctct_grp']['table_name'];
    $column = $custom_fields['ctct_grp']['fields']['ctct_id']['column_name'];
    $query = "
        SELECT c.entity_id, c.$column as ctct_id, g.saved_search_id
        FROM $table c
             LEFT JOIN civicrm_group g ON g.id = c.entity_id
        WHERE $column > ''";
    $dao = CRM_Core_DAO::executeQuery( $query );
    while ( $dao->fetch( ) ) {
        $row = $dao->toArray();
        $sync_groups[$row['entity_id']] = $row;
    }
    return $sync_groups;
}

function civi2ctct( $contact, $ctct_list ) {
  $props = array();
  // ATTENTION: substr('', 0, 50) and substr(NULL, 0, 50) return false, which is invalid for CtCt
  // hence we are testing with !empty() rather than isset() or CRM_Utils_Array::value()
  foreach (array('first_name', 'last_name') as $attr) {
    if (!empty($contact[$attr])) {
      $props[$attr] = substr($contact[$attr], 0, 50);
    }
  }
  if (!empty($contact['organization_name'])) {
    $props['company_name'] = substr($contact['organization_name'], 0, 50);
  }
  if (!empty($contact['email'])) {
    $props['email_addresses'] = array( array(
      'email_address' => substr($contact['email'], 0, 80),
      'confirm_status' => 'NO_CONFIRMATION_REQUIRED',
    ));
  }
  $props['lists'] = array();
  if ($contact['group_list']) {
      foreach( explode( ',', $contact['group_list'] ) as $civi_id) {
        $props['lists'][] = array(
          'id' => $ctct_list[$civi_id]['ctct_id'],
          'status' => 'ACTIVE',
          );
      }
  }
  if(!empty($props) && ! is_null($props)) {
    $ctct = Contact::create($props);
  }
  return $ctct;
}

// Merges two ConstantContact contacts
function merge_contact( $new, $old ) {
    // merge attributes from new (coming from civi) into old (coming from CtCt)
    // so this preserves all CtCt attributes ... including Status!
    foreach (array('first_name', 'last_name', 'company_name') as $attr) {
        if (!empty($new->$attr)) {
          $old->$attr = $new->$attr;
        }
    }
    // if the contact has changed email addresses, substitute for new email address
    if (($new_email = reset($new->email_addresses)) && ($old_email = reset($old->email_addresses))) {
      if ($old_email->email_address != $new_email->email_address) {
        $old->email_addresses = $new->email_addresses;
      }
    }
    // merge newly subscribed mailing lists from new in to old
    foreach ($new->lists as $new_list) {
        $found = false;
        foreach ($old->lists as $old_list) {
          if ($old_list->id == $new_list->id) {
            $found = true;
          }
        }
        if (!$found) {
          $old->lists[] = $new_list;
        }
    }
    // TODO: ONLY for synch'ed lists, delete unsubscribed mailing list from new in to old
    // and we're done ...
    return $old;
}
