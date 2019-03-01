<?php
/* ATMOCOM current weather JSON/XML formatter
** This script will fetch the last record and historical data from locally stored SQLite database
** and output the result in either JSON or XML format. In default configuration 
** script must be in directory directly above /wxdb directory.
**
** XML formatting is ***experimental*** in PHP and requires module xmlrpc to be enabled
** For JSON formatting: http://www.mysite.com/acpwx.php?format=j
** For XML formatting: http://www.mysite.com/acpwx.php?format=x
*/
$start = microtime(true);
 
$dataFolder = "wxdb/";
$dbFile = $dataFolder . "wx". date("Ym"). ".db";
$recsFile = $dataFolder . "wxdata.txt";

$dateFormat = "Y-m-d";
$timeFormat = "H:i:s";
$timeFormatS = "H:i";

if (array_key_exists('format', $_REQUEST)) $format=$_REQUEST['format'];
else $format="j";

$data = dbget_last();
$data[1] = dbget_stats();

if(count($data[0]) > 0)
{
	if($format === "j") echo json_encode($data, JSON_PRETTY_PRINT );
	else {
		//Change NULL values to 0 otherwise XML will be malformed
		$data2 = array_map(function($value) {
			return empty($value) ? "0" : $value;
		}, $data[0]); // array_map should walk through $data array
		
		$data2+=$data[1];
		echo xmlrpc_encode($data2);
	}
}
else die();

$time_elapsed_secs = microtime(true) - $start;
//echo $time_elapsed_secs; //Debug performance


//Extract and compute various stats. 
//SQL queries are working but not optimized in any way
//TODO: Optimize more
function dbget_stats()
{
	global $dbFile, $dateFormat, $timeFormat, $timeFormatS;
	$cdate = date($dateFormat);
	
	//echo $cdate;
	try {
		$db = new PDO('sqlite:' . $dbFile);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// Get BARO max and max time
		$sql_qry = 'SELECT w1.* FROM wxdata w1 where "DATE"="' . $cdate . '" AND "BARO"=(SELECT MAX(w2.BARO) FROM wxdata w2 where "DATE" = w1.DATE ) ORDER BY w1.TIME DESC LIMIT 1';
		$stmt = $db->query($sql_qry);
		$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$wxx['BARO_MAX'] = strval(round($res[0]['BARO'],1));
		$wxx['BARO_MAXTIME'] = date($timeFormatS, strtotime($res[0]['TIME']));

		// Get BARO min and min time
		$sql_qry = 'SELECT w1.* FROM wxdata w1 where "DATE"="' . $cdate . '" AND "BARO"=(SELECT MIN(w2.BARO) FROM wxdata w2 where "DATE" = w1.DATE ) ORDER BY w1.TIME DESC LIMIT 1';
		$stmt = $db->query($sql_qry);
		$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$wxx['BARO_MIN'] = round($res[0]['BARO'], 1);
		$wxx['BARO_MINTIME'] = date($timeFormatS, strtotime($res[0]['TIME']));

		// Get TEMP max and max time
		$sql_qry = 'SELECT w1.* FROM wxdata w1 where "DATE"="' . $cdate . '" AND "TEMP"=(SELECT MAX(w2.TEMP) FROM wxdata w2 where "DATE" = w1.DATE ) ORDER BY w1.TIME DESC LIMIT 1';
		$stmt = $db->query($sql_qry);
		$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$wxx['TEMP_MAX'] = round($res[0]['TEMP'],1);
		$wxx['TEMP_MAXTIME'] = date($timeFormatS, strtotime($res[0]['TIME']));

		// Get TEMP min and min time
		$sql_qry = 'SELECT w1.* FROM wxdata w1 where "DATE"="' . $cdate . '" AND "TEMP"=(SELECT MIN(w2.TEMP) FROM wxdata w2 where "DATE" = w1.DATE ) ORDER BY w1.TIME DESC LIMIT 1';
		$stmt = $db->query($sql_qry);
		$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$wxx['TEMP_MIN'] = round($res[0]['TEMP'],1);
		$wxx['TEMP_MINTIME'] = date($timeFormatS, strtotime($res[0]['TIME']));
		
		//Get last 50 baro and temp readings for trend
		$sql_qry = 'SELECT BARO, TEMP, WINDDIR, WINDVEL FROM wxdata ORDER BY id DESC LIMIT 50';
		$stmt = $db->query($sql_qry);
		$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
		$trend = barotemp_trend($res);
		$wxx['BARO_TREND'] = $trend['TREND_BARO'];
		$wxx['TEMP_TREND'] = $trend['TREND_TEMP'];
		$wxx['WDIR_AVG'] = $trend['WDIR_AVG'];

		} catch(PDOException $e) {
		// Print PDOException message
		echo $e->getMessage();
		die("Database error: " . $e->getMessage());
	}
	$db=null;
	
	//finally get annual total rain
	$rain = dbget_rain();
	$wxx['RAIN_TODAY'] = $rain['RAIN_DAY'];
	$wxx['RAIN_YESTERDAY'] = $rain['RAIN_YDAY'];
	$wxx['RAIN_MONTH'] = $rain['RAIN_MONTH'];
	$wxx['RAIN_RATE'] = $rain['RAIN_RATE'];
	$wxx['RAIN_YEAR'] = $rain['RAIN_YEAR'];
	return $wxx;
}


/* Get rain current and totals
** Annual, month totals + yesterday data are stored in file
** Daily total is added to Annual and month totals
** Yesterday requires a new query when the date changes, so once per day
** File format:
** #record Y M D value
*/
function dbget_rain()
{
	global $data, $dbFile, $recsFile, $dateFormat;
	
	$sqlA = false;
	$sqlM = false;
	$sqlY = false;
	$totalA = 0; //totals Annual, Month and Yesterday and now
	$totalM = 0;
	$totalY = 0;
	$totalN = 0;
	$rateN = 0;
	
	//Read records to determine which SQL queries are needed
	if(file_exists($recsFile)) 
	{
		$filedata = file_get_contents($recsFile);
		$recs = explode(PHP_EOL, $filedata);
		foreach($recs as $row)
		{
			$rdata = explode(" ", $row);
			if(count($rdata) < 4) continue;
			if($rdata[0]==0) //Annual total
			{
				//If year has changed a new annual query is required
				if(date('Y') > $rdata[1]) $sqlA = true;
				else $totalA = $rdata[4];
			}
			else if($rdata[0]==1) //Month total
			{
				//If month has changed a query for the new month is required
				if(date('m') !== $rdata[1]) $sqlM = true;
				else $totalM = $rdata[4];

			}
			else if($rdata[0]==1) //Yesterday total
			{
				//If day has changed a query for today is required
				if(date('d') !== $rdata[1]) $sqlY= true;
				else $totalY = $rdata[4];
			}
		}
	}
	else //file does not exist, all queries must be done
	{
		$sqlA = $sqlM = $sqlY = true;
	}
	
	//If no annual record then do the SQL. 
	//Will only happen once a year unless file is deleted
	if($sqlA) $totalA = rain_year();
	
	//Will only happen once a day unless file is deleted
	//TODO: this can be further optimized
	if($sqlM || $sqlY) 
	{
		try {
			$db = new PDO('sqlite:' . $dbFile);
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			//Get monthly total rain and today
			$sql_qry = 'SELECT DATE, PRECIP, PRECIPDAY FROM wxdata';
			$stmt = $db->query($sql_qry);
			$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$rain = rain_month_day($res);
			$totalN = $rain['RAIN_DAY'];
			$rateN = $rain['RAIN_RATE'];
			
			$totalY = $rain['RAIN_YDAY'];
			$totalM = $rain['RAIN_MONTH'];
		} catch(PDOException $e) {
			// Print PDOException message
			echo $e->getMessage();
			die("Database error: " . $e->getMessage());
		}
		$db=null;		
	} else
	{
		//Global $data holds current readings, queried earlier in the program. 
		$totalN = floatval($data[0]['PRECIPDAY']);
		$rateN = floatval($data[0]['PRECIP']);
		//Accumulate annual and month
		$totalM += $totalN;
	}

	$acc = array();
	$acc[0] = array( '0', date('Y'), date('m'), date('d'), $totalA );
	$acc[1] = array( '1', date('Y'), date('m'), date('d'), $totalM );
	$acc[2] = array( '2', date('Y'), date('m'), date('d'), $totalY );
	
	$outres = "";
	for($i=0; $i<count($acc); $i++)
	{
		$outres .=  $acc[$i][0] . " " . $acc[$i][1] . " " . $acc[$i][2] . " " . $acc[$i][3] . " " . $acc[$i][4];
		if($i<count($acc)-1) $outres .= "\r\n";
	}
	
	file_put_contents($recsFile, $outres);

	$rain['RAIN_MONTH'] = $totalM;
	$rain['RAIN_DAY'] = $totalN;
	$rain['RAIN_RATE'] = $rateN;
	$rain['RAIN_YDAY'] = $totalY;
	$rain['RAIN_YEAR'] = $totalA + $totalN;
	
	return $rain;
}

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
		echo $e->getMessage();
		die("Database error: " . $e->getMessage());
	}
	$db=null;
	return $res;
}

function rain_year()
{
	global $dataFolder;
	$currMon = date("m");
	$rain_year = 0.0;
	
	for($i=1; $i<=$currMon; $i++)
	{
		$dbf = $dataFolder . "wx". date("Y") . sprintf("%02d", $i) . ".db";
		
		if(!file_exists($dbf)) continue;
		
		try {
			$db = new PDO('sqlite:' . $dbf);
			$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			$stmt = $db->prepare('SELECT DATE, PRECIP, PRECIPDAY FROM wxdata');
			$stmt->execute();
			$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$rain = rain_month_day($res);
			$rain_year += $rain['RAIN_MONTH'];
		} catch(PDOException $e) {
			echo $e->getMessage();
			die("Database error: " . $e->getMessage());
		}
		$db=null;
	}
	
	return $rain_year;
}

function rain_month_day($dset)
{
	global $dateFormat;
	$nrecs=count($dset);
	if($nrecs < 1) {
		$rain1['RAIN_MONTH'] = $rain1['RAIN_DAY'] = $rain1['RAIN_RATE'] =  $rain1['RAIN_YDAY'] = 0.0;	
		return $rain1;
	}
	$dt_idx_yd = date($dateFormat);
		
	$dt_idx=$dset[0]['DATE'];
	$raintot = 0.0;
	$rainyesterday = 0.0;
	
	for($i=1;$i<$nrecs; $i++)
	{
		if($dset[$i]['DATE'] !== $dt_idx)
		{
			$raintot+=$dset[$i-1]['PRECIPDAY'];
			$dt_idx=$dset[$i]['DATE'];
			
			//Get total rain for yesterday
			if($dt_idx === $dt_idx_yd) $rainyesterday = $dset[$i-1]['PRECIPDAY'];
		}
	}
	
	if($raintot == 0 && ($dset[$nrecs-1]['PRECIPDAY']+$rainyesterday) != 0 )
		$raintot = $dset[$nrecs-1]['PRECIPDAY']+$rainyesterday;
		
	$rain1['RAIN_MONTH'] = $raintot;
	$rain1['RAIN_DAY'] = $dset[$nrecs-1]['PRECIPDAY'];
	$rain1['RAIN_RATE'] = $dset[$nrecs-1]['PRECIP'];
	$rain1['RAIN_YDAY'] = $rainyesterday;

	
	return $rain1;
}
function barotemp_trend($dset)
{
	//For fastest approximate trend, quantize data set into 60/40 blocks and compare delta
	//We could use least squares for proper calc but this faster and good enuff for now
	$n=count($dset);
	$dset = array_reverse($dset); // Because we use DESC in SQL to get last readings so latest record is first before reversing
	$c80 = 0.6*$n;
	$c20 = $n-$c80;
	
	$ref_avg_baro = $ref_avg_temp = 0;
	$cmp_avg_baro = $cmp_avg_temp = 0;
	$wavg = 0.0;
	$wavg_n = 0;
	for($i=0;$i<$n;$i++)
	{
		if($i<$c80)
		{
			$ref_avg_baro+=$dset[$i]['BARO'];
			$ref_avg_temp+=$dset[$i]['TEMP'];
		}
		else {
			$cmp_avg_baro+=$dset[$i]['BARO'];
			$cmp_avg_temp+=$dset[$i]['TEMP'];
		}
		if($dset[$i]['WINDVEL'] > 0) {
			$wavg+=$dset[$i]['WINDDIR'];
			$wavg_n++;
		}
	}
	//Calculate average for each block and parameter
	$ref_avg_baro /= $c80;
	$ref_avg_temp /= $c80;
	$cmp_avg_baro /= $c20;
	$cmp_avg_temp /= $c20;
	
	//Wind direction average is for the entire set
	if($wavg_n != 0) $wavg /= $wavg_n;
	
	$d_baro = $cmp_avg_baro-$ref_avg_baro;
	$d_temp = $cmp_avg_temp-$ref_avg_temp;

	//Ignore changes less than 0.2 points
	if(abs($d_baro) < 0.2) $d_baro = 0;
	else $d_baro = round($d_baro,2);
	
	if(abs($d_temp) < 0.2) $d_temp = 0;
	$d_temp = round($d_temp, 1);
	
	$trend['TREND_BARO'] = $d_baro;
	$trend['TREND_TEMP'] = $d_temp;
	$trend['WDIR_AVG'] = $wavg;

	return $trend;
}
