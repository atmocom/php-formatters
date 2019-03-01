<?php
error_reporting(E_ALL ^ E_WARNING);

$dataFolder = "wxdb/";
$dbFile = $dataFolder . "wx". date("Ym"). ".db"; 
$csv_sep = ",";

$data = dbget_last();

echo implode($csv_sep, $data[0]);

function dbget_last()
{
	global $dbFile;
	if(!file_exists($dbFile)) die();
	
	try {
		$db = new PDO('sqlite:' . $dbFile);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$sql_qry = "SELECT * FROM wxdata WHERE ID >= (SELECT MAX(ID) FROM wxdata)";
		
		$stmt = $db->prepare($sql_qry);
		$stmt->execute();

		$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
	} catch(PDOException $e) {
		// Print PDOException message
		die("Database error: " . $e->getMessage());
	}
	$db=null;
	return $res;
}

?>
