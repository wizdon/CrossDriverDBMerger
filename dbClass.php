<?php
class db{
	/*
	* @the connection object and datastore
	*/
	private $conn = null;
	private $data;
	private $errors;
	private $supporteddrivers = array('mysql','sqlite');
	/*
	* @currently not sure whether singleton is good or bad practice
	*/
	
	public function __construct($dbdrv = null)
	{
		if($dbdrv !== null) $this->conn = null; //Close connection if any to assist db_driver overwrite[Still in doubt]
		$DBVARS = loadSetting();
		if($dbdrv === null || !in_array($dbdrv,$this->supporteddrivers)) $dbdrv = $DBVARS['dbdriver'];
		try
		{
			if($this->conn == null)
			{
				$opts = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false);
				if($dbdrv == 'mysql')
				{
					$this->conn = new PDO($dbdrv.':host='.$DBVARS['hostname'].';dbname='.$DBVARS['db_name'],$DBVARS['username'],$DBVARS['password'], $opts);
				}
				elseif($dbdrv == 'sqlite')
				{
					$dbf = ABSPATH.'.private/dbs/'.$DBVARS['db_name'].'.sdb';
					if(file_exists($dbf)) $this->conn = new PDO('sqlite:'.$dbf);
					else throw new PDOException('Database named "'.$DBVARS['db_name'].'" doesn\'t exist!');
				}
				else throw new PDOException('Database driver "'.$DBVARS['db_name'].'" doesn\'t exist or not supported!');
				//$this->conn->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
                if($this->conn) return $this->conn;
                else return null;
			}
		}
		catch(PDOException $e)
		{
			logError('dberror', $e->getMessage());//$e->xdebug_message;
			return null;
		}
        if($this->conn) return $this->conn;
        else return null;
	}
	
	public function getInstance()
	{
		return $this->conn;
	}
	
	public function __destruct()
	{
		$this->conn = null;
		$this->data = null;
	}
	
	public function query($query, $params=false)
	{
		$qry = trim($query,';');
		$multiq = explode(';', $qry);
		//$pattern = '/drop|truncate|delete|create/i';//Dangerous query, we currently can\'t handle for security reasons
		if(count($multiq)>1 || empty($qry)/* || preg_match($pattern,$qry) !== false*/) return null; // Sipendi ujinga
		if(stripos($qry,'insert') !== false || stripos($qry,'replace') !== false) $method = 'liid';
		elseif(stripos($qry,'select') !== false || stripos($qry,'PRAGMA') !== false) $method = 'res';
		else $method = 'rc';
		try
		{
			$stmt = $this->sendData($qry, $params);
            if(!$stmt)return null;
			if($method == 'rc') return $stmt->rowCount();
			elseif($method == 'liid') return $stmt;
			elseif($method == 'res') return $stmt->fetchAll(PDO::FETCH_ASSOC);
			else return null;
		}
		catch(Exception $e)
		{
			return null;/* Because people are not trustworthy, let's take care of things silently*/
		}
	}
	
	public function queryAll($table, $start = 0, $count = 25)
	{
		$qry = 'SELECT * FROM `'.$table.'` WHERE 1 LIMIT '.$start.','.$count;
		$stmt = $this->sendData($qry, null);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	
	public function queryRow($table, $data=null, $cond=null)
	{
		$qry = 'SELECT * FROM `'.$table.'` WHERE '.$this->createCond($cond).' LIMIT 0,1';
		$stmt = $this->sendData($qry, $data);
		$res  = $stmt->fetch(PDO::FETCH_ASSOC);
		return $res;
	}
	
	public function queryField($table, $field, $data, $cond=null)
	{
		return $this->queryOne($table, $field, $data, $cond);
	}
	
	public function queryOne($table, $field, $data, $cond=null)
	{
		$res = $this->queryRow($table, $data, $cond);
		if(is_array($res) && isset($res[$field])) return $res[$field];
		else return null;
	}
	
	public function queryCount($table, $field, $cond = null, $data = null)
	{
		$qry = 'SELECT COUNT('.$field.') AS count FROM `'.$table.'` WHERE '.$this->createCond($cond);
		$stmt = $this->sendData($qry, $data);
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}
	
	public function select($table, $data = null, $cond = null, $columns = '*')
	{
		$qry = 'SELECT * FROM `'.$table.'` WHERE '.$this->createCond($cond);
		$stmt = $this->sendData($qry, $data);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	
	public function insert($table,array $data)
	{
		return $this->putMode($table, $data, 'insert');
	}
	
	public function update($table, array $data, $cond)
	{
		$colsval = '';
		$sd = $data;
		if( isset($data[0]) && is_array($data[0]) ) $sd = $data[0];
		foreach($sd as $key=>$value) $colsval .= $key.'=:'.$key.',';
		$qry = "UPDATE ".$table." SET ".substr($colsval, 0, -1)." WHERE ".$this->createCond($cond);
		$stmt = $this->sendData($qry, $data);
		return $stmt->rowCount();
	}
	
	public function insertUpdate($table, array $data)
	{
		
		return $this->putMode($table, $data, 'replace');
	}
	
	public function replace($table,array $data)
	{
		return $this->insertUpdate($table, $data);

	}
	
	public function delete($table, $cond = null, $data=null)
	{
		$qry = "DELETE FROM ".$table." WHERE ".$this->createCond($cond);
		if(is_array($cond))
		{ 
			if(is_array($data)) $data = array_merge($data, $cond);
			else $data = $cond;
		}
		$stmt = $this->sendData($qry, $data);
		return $stmt->rowCount();
	}
	
	public function showTables()
	{
	    if(!$this->conn)return null;
		$DBVARS = loadSetting();
		$DBVARS['dbdriver'] = $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME);
		if($DBVARS['dbdriver'] == "sqlite")	$qry = "SELECT name FROM sqlite_master WHERE type='table' AND name !='sqlite_sequence'";
		elseif($DBVARS['dbdriver'] == "mysql") $qry = "SHOW tables";
		else return array();
		$stmt = $this->sendData($qry,null);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		if(!is_array($rows) || !$rows) return false;
		$tables = array();
		foreach($rows as $row) $tables = array_merge($tables,array_values($row));
		$dbdata = array();
		$cq = 'SELECT COLUMN_NAME AS name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:table';
		foreach($tables as $table) 
		{
			if($DBVARS['dbdriver'] == "sqlite")$tmp = $this->query("PRAGMA table_info($table)");
			else $tmp = $this->query($cq,array('db'=>$DBVARS['db_name'],'table'=>$table));/*TODO: query columns*/
			$cols = array();
			foreach($tmp as $f) $cols[] = $f['name'];
			$dbdata[$table] = $cols;
		}
		return $dbdata;
	}
	
	private function putMode($table, array $data, $mode)
	{
		if(!$table || empty($data)) return null;
		$sd = $data;
		if( isset($data[0]) && is_array($data[0]) ) $sd = $data[0];
		$arrkeys = array_keys($sd);
		$cols = implode('`, `',$arrkeys);
		$params = ':'.implode(', :',$arrkeys);
		if(strtolower($mode) == 'insert') 
			$qry = "INSERT INTO `".$table."` (`".$cols."`) VALUES (".$params.")";
		else 
			$qry = "REPLACE INTO `".$table."` (`".$cols."`) VALUES (".$params.")";
		return $this->sendData($qry, $data, true);
	}
	
	private function createCond($cond)
	{
		if(is_string($cond))
		{
			$whr = $cond;
		}
		elseif(is_array($cond))
		{
			$whr = array();
			foreach($cond as $key=>$col)
			{
				$whr[] = $key.'=:'.$key;
			}
			$whr = implode("' AND '",$whr);
		}
		else
		{
			$whr = 1;
		}
		return $whr;
	}
	private function bindData($stmt, $key, $value)
	{
		switch($value)
		{
			case is_int($value):	$type = PDO::PARAM_INT;
			case is_bool($value):	$type = PDO::PARAM_BOOL;
			case is_null($value):	$type = PDO::PARAM_NULL;
			/*case is_blob($value):	$type = PDO::PARAM_LOB;*/
			default: $type = PDO::PARAM_STR;
		}
		$stmt->bindValue($key, $value, $type);
		return $stmt;
	}
	private function sendData($qry, $params, $liid = false)
	{
        if(!$this->conn)return null;
		$stmt = $this->conn->prepare($qry);
        if(!$stmt)return null;
		if(!is_array($params)) 
		{
			$stmt->execute();
			return $stmt;
		}
		try{
			$useTrans = ( isset($params[0]) && is_array($params[0]))?true:false;
			if($useTrans) $this->conn->beginTransaction();
			foreach($params as $key=>$value)
			{
				if(is_array($value))
				{
					foreach($value as $k=>$v) 
					{
						$stmt = $this->bindData($stmt, $k, $v);
					}
					$stmt->execute();
				}
				else
				{
					$this->bindData($stmt, $key, $value);
				}
			}
			if($useTrans) 
			{
				$ret = @$this->conn->lastInsertId();
				$this->conn->commit();
			}
			else 
			{
				$stmt->execute();
				$ret = @$this->conn->lastInsertId();
			}
			if(!$liid) $ret = $stmt;
			return $ret;
			
		}
		catch (PDOException $e)
		{
			logError('dberror', $e->getMessage());//$e->xdebug_message;
			return null;
		}
	}
}
