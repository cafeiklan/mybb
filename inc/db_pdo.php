<?php

class pdoEngine {

	/**
	 * The database class to store PDO objects
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * The last query resource that ran
	 *
	 * @var object
	 */
	var $last_query = "";
	
	var $seek_array = array();

	/**
	 * Connect to the database.
	 *
	 * @param string The database DSN.
	 * @param string The database username. (depends on DSN)
	 * @param string The database user's password. (depends on DSN)
	 * @param array The databases driver options (optional)
	 * @return boolean True on success
	 */
	function pdoEngine($dsn, $username="", $password="", $driver_options=array())
	{
		try
		{
    		$this->db = new PDO($dsn, $user, $password, $driver_options);
		} 
		catch(PDOException $exception)
		{
    		echo 'Connection failed: '.$exception->getMessage();
		}
		
		return true;
	}
	
	/**
	 * Query the database.
	 *
	 * @param string The query SQL.
	 * @return resource The query data.
	 */
	function query($string)
	{
		//echo htmlentities($string)."<br />";
		$query = $this->db->query($string, PDO::FETCH_BOTH);		
		$this->last_query = $query;
				
		return $query;
	}
	
	/**
	 * Return a result array for a query.
	 *
	 * @param resource The query resource.
	 * @return array The array of results.
	 */
	function fetch_array($query)
	{
		if(!is_object($query))
		{
			return;
		}
		
		if($this->seek_array[md5((string)$query)])
		{
			$array = $query->fetch(PDO::FETCH_BOTH, $this->seek[$query]['offset'], $this->seek[$query]['row']);
		}
		else
		{
			$array = $query->fetch(PDO::FETCH_BOTH);
		}/*
		echo "<pre>";
		print_r($array);
		echo "</pre>";*/
		return $array;
	}
	
	/**
	 * Moves internal row pointer to the next row
	 *
	 * @param resource The query resource.
	 * @param int The pointer to move the row to.
	 */
	function seek($query, $row)
	{
		if(!is_object($query))
		{
			return;
		}
		
		$this->seek_array[md5((string)$query)] = array('offset' => PDO::FETCH_ORI_ABS, 'row' => $row);
		
	}
	
	/**
	 * Return the number of rows resulting from a query.
	 *
	 * @param resource The query resource.
	 * @return int The number of rows in the result.
	 */
	function num_rows($query)
	{
		if(!is_object($query))
		{
			return;
		}
		
		return count($query->rowCount());
	}
	
	/**
	 * Return the last id number of inserted data.
	 *
	 * @param string The name of the insert id to check. (Optional)
	 * @return int The id number.
	 */
	function insert_id($name="")
	{
		return $this->db->lastInsertId($name);
	}
	
	/**
	 * Return an error number.
	 *
	 * @param resource The query resource.
	 * @return int The error number of the current error.
	 */
	function error_number($query)
	{
		if(!is_object($query))
		{
			return;
		}
		$errorcode = $query->errorCode();
		//echo " - $errorcode -";
		return $errorcode;
	}
	
	/**
	 * Return an error string.
	 *
	 * @param resource The query resource.
	 * @return int The error string of the current error.
	 */
	function error_string($query)
	{
		if(!is_object($query))
		{
			return $this->db->errorInfo();
		}
		return $query->errorInfo();
	}
	
	/**
	 * Roll back the last query.
	 *
	 * @return boolean true on success, false otherwise.
	 */
	function roll_back()
	{
		//return $this->db->rollBack();
	}
	
	/**
	 * Returns the number of affected rows in a query.
	 *
	 * @return int The number of affected rows.
	 */
	function affected_rows($query)
	{
		return $query->rowCount();
	}
	
	/**
	 * Return the number of fields.
	 *
	 * @param resource The query resource.
	 * @return int The number of fields.
	 */
	function num_fields($query)
	{
		return $query->columnCount();
	}
	
	function escape_string($string)
	{
		$string = $this->db->quote($string);	
		
		// Remove ' from the begginging of the string and at the end of the string, because we already use it in insert_query
		$string = substr($string, 1);
		$string = substr($string, 0, -1);
		
		return $string;
	}
	
	/**
	 * Return a selected attribute
	 *
	 * @param constant The attribute to check.
	 * @return string The value of the attribute.
	 */
	function get_attribute($attribute)
	{
		$attribute = constant("PDO::{$attribute}");
		
		return $attribute;
	}
}

?>