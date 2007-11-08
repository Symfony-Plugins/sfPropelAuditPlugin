<?php
/**
 * sfPropelAuditBehavior
 * Adds audit tracking to Propel objects.
 *
 * @package    symfony
 * @subpackage plugin
 * @author     Sacha Telgenhof Oude Koehorst <s.telgenhof@xs4all.nl>
 * @version    SVN: $Id:$
 */
class sfPropelAuditBehavior
{
  protected 
    /**
     * Holds the date format that is being used for storing the entry in the audit table.
     *
     * @var string
     */
    $dateFormat = 'Y-m-d H:i:s';

  const
    TYPE_ADD    = 'ADD',
    TYPE_UPDATE = 'UPDATE',
    TYPE_DELETE = 'DELETE',
    TYPE_SELECT = 'SELECT';

  /**
   * Hook function to the Peer Class function doUpdate (post)
   *
   * @param array $class The name of the object
   * @param mixed $values Criteria or object containing data that is used to create the UPDATE statement.
   * @param Connection $con The connection to use (specify Connection object to exert more control over transactions).
   * @param int $affectedrows The number of affected rows (if supported by underlying database driver).
   * @return bool
   */
  public function postDoUpdate($peer_class, $values, $con, $affectedrows)
  {
    // do not keep an audit record when the object has not been changed
    // or when no rows are affected
    if (!$values->isModified() || !$affectedrows)
    {
      return false;
    }
        
    $class = get_class($values);
    $classMapBuilder = $class.'MapBuilder';

    if (!$classPath = sfCore::getClassPath($classMapBuilder)) 
    {
      throw new sfException(sprintf('Unable to find path for class "%s".', $classMapBuilder));
    }
    
    require_once ($classPath);
    
    $map = new $classMapBuilder();
    $map->doBuild();
    $tableMap = $map->getDatabaseMap()->getTable(constant($peer_class.'::TABLE_NAME'));

    foreach (call_user_func(array($peer_class, 'getFieldNames'), BasePeer::TYPE_COLNAME) as $column) 
    {
      // do not keep track of changes for fields that are primary keys or for the field 'updated_at'
      if ($tableMap->getColumn($column)->getColumnName() == 'UPDATED_AT')
      {
        continue;
      }
      
      if ($tableMap->getColumn($column)->isPrimaryKey())
      {
        continue;
      }

      if ($values->isColumnModified($column)) 
      {
        $column_phpname = call_user_func(array($peer_class, 'translateFieldName'), $column, BasePeer::TYPE_COLNAME, BasePeer::TYPE_PHPNAME);
        $method = 'get'.sfInflector::camelize($column_phpname);
        $changes[$column_phpname] = $values->$method();
      }
    }
    
    $this->save($class, $values->getPrimaryKey(), serialize($changes), $con->getLastExecutedQuery(), self::TYPE_UPDATE);
    
    return true;
  }

  /**
   * Hook function to the Peer Class function doInsert (post)
   *
   * @param array $class The name of the object
   * @param mixed $values Criteria or object containing data that is used to create the INSERT statement.
   * @param Connection $con The connection to use (specify Connection object to exert more control over transactions).
   * @param mixed $pk The primary key of the object
   * @return bool
   */
  public function postDoInsert($class, $values, $con, $pk)
  {
    if (!$values->isNew())
    { 
      return false;
    }
    
    $this->save(get_class($values), $values->getPrimaryKey(), null, $con->getLastExecutedQuery(), self::TYPE_ADD);
  }

  /**
   * Hook function to the Base Class function Save (post)
   *
   * @param array $object The name of the object
   * @param Connection $con The connection to use (specify Connection object to exert more control over transactions).
   * @return bool
   */
  public function postDelete($object, $con = null)
  {
    if (!$object->isDeleted()) 
    {
      return false;
    }
    
    $this->save(get_class($object), $object->getPrimaryKey(), null, $con->getLastExecutedQuery(), self::TYPE_DELETE);
  }

  /**
   * Internal function which will create an audit record for the object that was
   * being tracked.
   *
   * @param string $object The name of the object that was being tracked.
   * @param mixed  $object_key The primary key of the object that was being tracked.
   * @param string $changes A (serialized) string containing the individual changes of the object.
   * @param string $query The SQL query that was executed for this record.
   * @param string $type The audit type. This can be one of the following constants: 
   *               TYPE_ADD, TYPE_UPDATE, TYPE_DELETE, or TYPE_SELECT
   * @return void
   */
  private function save($object, $object_key, $changes, $query, $type)
  {
    $user = sfContext::getInstance()->getUser();
    
    if ($user->isAnonymous())
    {
      $user = null;
    }

    $audit = new sfAudit();
    $audit->setRemoteIpAddress($this->getRemoteIP());
    $audit->setObject($object);
    $audit->setObjectKey($object_key);
    $audit->setObjectChanges($changes);
    $audit->setQuery($query);
    $audit->setUser($user);
    $audit->setType($type);
    $audit->setCreatedAt(date($this->dateFormat));
    $audit->save();
  }

  /**
   * Internal function which determines the remote IP address.
   * If Propel objects are changed via a CLI script (batch) the local
   * loopback address will be returned.
   *
   * @return string
   */
  private function getRemoteIP()
  {
    $ip = false;

    // User is behind a proxy and check that we discard RFC1918 IP addresses.
    // If these address are behind a proxy then only figure out which IP belongs to the user.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) 
    {
      // put the IP's octets into an array
      $ips = explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']); 
      $no = count($ips);
      
      for ($i = 0 ; $i < $no ; $i++) 
      {
        // Skip RFC 1918 IP's 10.0.0.0/8, 172.16.0.0/12 and
        // 192.168.0.0/16
        if (!eregi('^(10|172\.16|192\.168)\.', $ips[$i])) 
        {
          $ip = $ips[$i];
          break;
        }
      }
    }
    
    return ($ip ? $ip : isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1'); // Return with the found IP, the remote address or the local loopback address
  }
}
