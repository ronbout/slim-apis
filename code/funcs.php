<?php 

use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;


function get_pass_info($companyCode) {
	return @file('C:\Users\ron\Documents\passwords\apis\\'.$companyCode.".txt");
	//return @file('/var/www/passwords/apis/'.$companyCode.".txt");
}


function build_update_SQL_cols( $post_data, $table_cols ) {
	$sql_cols = '';
	$col_names = array();

	for ($i = 0; $i < count($table_cols); $i++) {
		$col_name = $table_cols[$i];
		if (array_key_exists($col_name, $post_data)) {
			$col_string = isset($post_data[$col_name]) ? filter_var($post_data[$col_name], FILTER_SANITIZE_STRING) : null;
			// REST cannot send null value, so using #N/A
			$col_string = ($col_string == '#N/A') ? null : $col_string;
			$sql_cols .= ($sql_cols) ? ', ' : '';
			$sql_cols .= $col_name . ' = ?';
			$col_names[] = $col_string;
		}
	}	
	return array($sql_cols, $col_names);
}

function json_connect(Request $request, Response $response, $configLoc, &$errCode) {
	// if there is an error, $errCode will be set and a Response will be returned
	// otherwise, the json file

	$data = array();
	$errCode = 0;
	// have to get company code and api key..or error
	$query = $request->getQueryParams();
	if ( !isset($query['api_cc']) || !isset($query['api_key']) ) {
		$errCode = -3;
		$data['error'] = true;
		$data['message'] = 'Company Code and API Key are required.';
		$newResponse = $response->withJson($data, 400, JSON_NUMERIC_CHECK );
		return $newResponse;
	}
	
	$passInfo = get_pass_info($query['api_cc']);
	if (!$passInfo) {
		$errCode = -1;   // error -1 could not retrieve user/password info
		$data['error'] = true;
		$data['message'] = 'Error retrieving api information.';
		$newResponse = $response->withJson($data, 500, JSON_NUMERIC_CHECK );
		return $newResponse;
	}

	$apiKey = trim($passInfo[3]);
	
	if ( $apiKey != $query['api_key'] ) {
		$errCode = -2; // error code -2 invalid api key
		$data['error'] = true;
		$data['message'] = 'Invalid API Key.';
		$newResponse = $response->withJson($data, 401, JSON_NUMERIC_CHECK );
		return $newResponse;
	}
	
	if (! $configFile = @file_get_contents($configLoc)) {
		$errCode = -4; // error code -4 could not get config file
		$data['error'] = true;
		$data['message'] = 'Error retrieving config file.';
		$newResponse = $response->withJson($data, 500, JSON_NUMERIC_CHECK );
		return $newResponse;
	}
	
	return json_decode($configFile, true);
}

function db_connect(Request $request, Response $response, &$errCode, $api_key=null) {
	// if there is an error, $errCode will be set and a Response will be returned
	// otherwise, the db connection will be returned

	$data = array();
	// have to get  api key..or error
	if (! $api_key) {
		$query = $request->getQueryParams();
		$api_key = isset($query['api_key']) ? $query['api_key'] : null;
	} 

	if (  ! $api_key ) {
		$errCode = -3;
		$data['error'] = true;
		$data['message'] = 'API Key is required.';
		$newResponse = $response->withJson($data, 400, JSON_NUMERIC_CHECK );
		return $newResponse;
	}
	
	// connect to database
 	$errCode = '';
	if (!($db = pdoConnect($errCode, $api_key))) {
		switch($errCode) {
			case -2:
				$data['error'] = true;
				$data['message'] = 'Invalid API Key.';
				$newResponse = $response->withJson($data, 401, JSON_NUMERIC_CHECK );
				return $newResponse;
				break;
			case -1: 
			default:
				$data['error'] = true;
				$data['message'] = 'Database Connection Error: ' . $errCode;
				$newResponse = $response->withJson($data, 500, JSON_NUMERIC_CHECK );
				return $newResponse;
		}
	}	
	$errCode = 0;
	return $db;
}

function pdoConnect(&$errorCode, $apiKeySent) {
	// connects to database $db using PDO for mysql
	// must get user and password from passwords dir
	// returns either PDO object or error code

	$errorCode = 0;
	$passInfo = get_pass_info('abc');
	if (!$passInfo) {	
		$errorCode = -1;   // error -1 could not retrieve user/password info
		return false;
	}
	
	$user = trim($passInfo[0]);
	$password = trim($passInfo[1]);
	$db = trim($passInfo[2]);
	$apiKey = trim($passInfo[3]);
	
	if ( $apiKey != $apiKeySent ) {
		$errorCode = -2; // error code -2 invalid api key
		return false;
	}
	
	$opts = array(
		PDO::MYSQL_ATTR_FOUND_ROWS => true,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
	);
    try {
        $cn = new PDO('mysql:dbname=' . $db . ';host=127.0.0.1', $user, $password, $opts);
    } catch (PDOException $e) {
        $errorCode = $e->getMessage();
        return false;
    }
	return $cn;
}

function pdo_exec( Request $request, Response $response, $db, $query, $execArray, $errMsg, &$errCode, $checkCount = false, $ret_array_flg = false, $return_flg = true ) {
	$stmt = $db->prepare ( $query );
	$data = array();
	
	if (! $stmt->execute ( $execArray )) {
		$errCode = true;
		$data ['error'] = true;
		$data ['errorCode'] = $stmt->errorCode();
		$data ['message'] = 'Database SQL Error ' . $errMsg . ' ' . $stmt->errorCode () . ' - ' . $stmt->errorInfo () [2];
		$data = array('data' => $data);
		$newResponse = $response->withJson ( $data, 500, JSON_NUMERIC_CHECK );
		return $newResponse;
	}
	
	// the stmt rowCount only matters if we are supposed to return a value
	if ( $stmt->rowCount () == 0 && ($ret_array_flg || $return_flg)) {
		if ( $checkCount ) {
			$errCode = true;
			$data = array();
			$data ['error'] = false;
			$data ['message'] = 'No records Found';
			$newResponse = $response->withJson ( $data, 200, JSON_NUMERIC_CHECK );
			return $newResponse;
		} else {
			return null;
		}
	}
	
	if ( $ret_array_flg ) {
		$ret_data = array();
		while ( ($info = $stmt->fetch ( PDO::FETCH_ASSOC )) ) {
			$ret_data [] = array_filter($info, function($val) {
				return $val !== null;
			});;
		}
		return $ret_data;
	} elseif ($return_flg) {
		return array_filter($stmt->fetch ( PDO::FETCH_ASSOC ), function($val) {
			return $val !== null;
		});;
	} else {
		return true;
	}
}

function runLowerObject( $obj, $newObjName, $fieldList = null ) {

	$obj_fields = array('personId', 
			'personFormattedName',
			'personGivenName',
			'personMiddleName',
			'personFamilyName',
			'personAffix',
			'personAddr1',
			'personAddr2',
			'personMunicipality',
			'personRegion',
			'personPostalCode',
			'personCountryCode',
			'personEmail1',
			'personEmail2',
			'personWebsite',
			'personHomePhone',
			'personWorkPhone',
			'personMobilePhone'
	);

	if ( is_callable($fieldList) ) {
		// we have a callback function to run against the default field list
		$flds = array_map($fieldList, $social_fields );
	} elseif ( is_array($fieldList) ) {
		$flds = $fieldList;
	} else {
		$flds = $obj_fields;
	}

	return createLowerObject( $obj, $newObjName, $flds );
}

function createLowerObject( $obj, $newObjName, $fieldList ) {
	$tmpObj = array();
	
	foreach( $fieldList as $fld ) {
		if ( array_key_exists($fld, $obj) ) {
			$tmpObj[$fld] = $obj[$fld];
			unset($obj[$fld]);
		}
	}
	
	count($tmpObj) && $obj[$newObjName] = $tmpObj;	
	return $obj;
}

function build_update_sql ($table, $fields, $data, $id_field, $id) {
	$parms = array();
	$set_str = '';
	foreach($fields as $field => $api_field) {
		if (isset($data[$api_field]) ) {
			$set_str .= " $field = ?,";
			$parms[] = $data[$api_field] === '' ? null : filter_var($data[$api_field]);
		}
	}
	// check that some fields need to be updated
	if (!count($parms)) return false;
	$set_str = trim($set_str, ',');
	$query = "UPDATE $table SET $set_str WHERE $id_field = $id";
	return array($query, $parms);
}
