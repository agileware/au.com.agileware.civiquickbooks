<?php

use Civi\Api4\EntityTag;

class CRM_Civiquickbooks_Helper {

  /**
   * @param int $contactID
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function addSyncErrorTag(int $contactID) {
    if (empty(EntityTag::get(FALSE)
      ->addWhere('entity_table:name', '=', 'Contact')
      ->addWhere('entity_id', '=', $contactID)
      ->addWhere('tag_id:name', '=', 'Quickbooks Sync Error')
      ->execute()->count())) {
      EntityTag::create(FALSE)
        ->addValue('entity_table:name', 'Contact')
        ->addValue('entity_id', $contactID)
        ->addValue('tag_id:name', 'Quickbooks Sync Error')
        ->execute();
    }
  }

  /**
   * @param int $contactID
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function removeSyncErrorTag(int $contactID) {
    EntityTag::delete(FALSE)
      ->addWhere('entity_table:name', '=', 'Contact')
      ->addWhere('entity_id', '=', $contactID)
      ->addWhere('tag_id:name', '=', 'Quickbooks Sync Error')
      ->execute();
  }

}
