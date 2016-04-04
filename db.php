<?php

class Error{    
    
    // store errors
    private $errors = array();
    
    // add errors
    protected function add_error($code,$str){
        $this->errors[$code]=$str;
    }
    // get errors
    function get_errors(){
        return $this->errors;
    }
}

class DB extends Error{

    private $db;
    private $sql='';
    private $replace=array();
    private $info; //for debug information
    private $alternate_begin='';
    private $debug_output='';
	
    public $debug_formatted=false;
    public $debug=false;
    public $lastInsertId='0';
    public $rowsAffected=0;
    public $error_count=0;
    public $return_type='object';
    public $raw=false; //disable prep_vars
    
    
    // initialize connection
    function __construct($params=''){
        $this->reconnect($params);
        $this->info=new stdClass();
    }
    
    //disconnect and reconnect to the database
    function reconnect($params=''){
        $connect=array(
            'name'=>DB_NAME,
            'password'=>DB_PASS,
            'user'=>DB_USER,
            'host'=>DB_HOST
        );
        
        //override connection defaults if necessary
        if(is_array($params)){
            if(isset($params['name']))$connect['name']=$params['name'];
            if(isset($params['password']))$connect['password']=$params['password'];
            if(isset($params['user']))$connect['user']=$params['user'];
            if(isset($params['host']))$connect['host']=$params['host'];
        }
        
        //close the existing database connection
        if($this->db!==null)
            $this->db=null;
        
        //connect to the database or die trying
        try {
            $dsn="mysql:dbname=".$connect['name'].";host=".$connect['host'];
            $this->db = new PDO($dsn, $connect['user'], $connect['password']);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->query('SET NAMES GBK');
        } catch (PDOException $e) {
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
    }
    
    // initialize the sql query
    private function begin_query($type){
        $this->errors=array();
        $this->sql=$type.' ';
        $this->error_count=0;
        $this->replace=array();
    }
    
    private function build_where($where){
        if(!empty($where)){
            $this->sql.=" WHERE ";
            if(is_array($where)){
                $c=count($this->replace);
                foreach($where as $key=>$w){
                    // checks the array key for a slash and then the operator (eg. id/%LIKE%)
                    $op=substr(strstr($key,'/'),1);
                    
                    // check for null variables
                    if(strtolower($w)=='null' || strtolower($w)=='!null' || $w===NULL){
                        if($op=='!=')
                            $val='IS NOT NULL';
                        else
                            $val='IS NULL';
                        
                        $this->sql.=$key.' '.$val.' && ';
                    }
                    //check for an empty string
                    elseif($op=='empty'){
                        $key=strstr($key,'/',true);
						$this->sql.=$key.'="" && ';
                    }
                    //check for an non-empty string
                    elseif($op=='notempty'){
                        $key=strstr($key,'/',true);
						$this->sql.=$key.'!="" && ';
                    }
                    // look for any comparitive symbols within the where array.
                    elseif($op!=''){
                        $key=strstr($key,'/',true);
                        switch($op){
                            case '%LIKE%':
                                $eq=' LIKE ';
                                $w='%'.$w.'%';
                            break;
                            case '%LIKE':
                                $eq=' LIKE ';
                                $w='%'.$w;
                            break;
                            case 'LIKE%':
                                $eq=' LIKE ';
                                $w=$w.'%';
                            break;
                            case '%NOTLIKE%':
                                $eq=' NOT LIKE ';
                                $w='%'.$w.'%';
                            break;
                            case '%NOTLIKE':
                                $eq=' NOT LIKE ';
                                $w='%'.$w;
                            break;
                            case 'NOTLIKE%':
                                $eq=' NOT LIKE ';
                                $w=$w.'%';
                            break;
                            default:
                                $eq=$op;
                            break;
                        }
                        
                        // prep the query for PDO->prepare
						if($op=='col'){
							//ignore replacement if comparing to another table column
							$this->sql.=$key.'='.$w.' && ';
						}
						else{
							$this->sql.=$key.$eq.':'.$c.' && ';
							$this->replace[':'.$c]=$w;
						}
                    }
                    elseif(substr($w,0,1)=='%'){
                        // prep the query for PDO->prepare
                        $this->sql.=$key.'=%:'.$c.'% && ';
                        $this->replace[':'.$c]=$w;
                    }
                    
                    //check for comparative symbols
                    //RETAIN FOR BACKWARDS COMPATIBILITY
                    else{
                        if(substr($w,0,2)=='<='){
                            $eq='<=';
                            $w=substr($w,2);
                        }
                        elseif(substr($w,0,2)=='>='){
                            $eq='>=';
                            $w=substr($w,2);
                        }
                        elseif(substr($w,0,1)=='>'){
                            $eq='>';
                            $w=substr($w,1);
                        }
                        elseif(substr($w,0,1)=='<'){
                            $eq='<';
                            $w=substr($w,1);
                        }
                        elseif(substr($w,0,1)=='!'){
                            $eq='!=';
                            $w=substr($w,1);
                        }
                        else{
                            $eq='=';
                        }
                            
                        
                        
                        // prep the query for PDO->prepare
                        $this->sql.=$key.$eq.':'.$c.' && ';
                        $this->replace[':'.$c]=$w;
                    }
                    $c++;
                }
                $this->sql=substr($this->sql,0,-4);
            }
            elseif(is_string($where)){
                $this->sql.=$where;
            }
        }
    }
    
    // remove slashes from all retrieved variables
    private function prep_vars($vars){
        //return the raw dataset
        if($this->raw) return $vars;
        
        if(is_array($vars)){
            foreach($vars as $key=>$value)
                $ret[$key]=$this->prep_vars($value);
        }
        elseif(is_object($vars)){
            $ret=new stdClass();
            foreach($vars as $key=>$value)
                $ret->$key=$this->prep_vars($value);
        }
        elseif(is_string($vars)){
            $ret=stripslashes($vars);
        }
        else{
            $ret=$vars;
        }
        return $ret;
    }
    
    
    // general query function
    function query($query,$vals=''){
        if($this->info->running==0){
            $this->info->running=1;
            $this->info->func='$db->query';
            $this->info->vars=array('$query'=>$query,'$vals'=>$vals);
        }
        
    
        // double check the database connection object is working
        if(!$this->db){
            $this->add_error('000','Database connection failed.');
            return false;
        }   
        // prep
        $sth=$this->db->prepare($query);
        
        // do it
        if($sth){
            if($vals)
                $pass=$sth->execute($vals);
            else
                $pass=$sth->execute();
        }
        else{
            $this->info->running=0;
            $this->get_sql_error($this->db->errorInfo(),'Error executing query');
            return false;
        }
        if($pass){
            if (substr($query,0,6)=="SELECT") {
                //grab
                if($this->return_type=='object')
                    $result=$sth->fetchAll(PDO::FETCH_OBJ);
                else
                    $result=$sth->fetchAll(PDO::FETCH_ASSOC);
            }
            else {
                //return number of affected rows if not a SELECT query
                $result=true;
            } 
            
			$this->rowsAffected=$sth->rowCount();
			
            //find any errors
            $er=$this->get_sql_error(array('000000'));
            
            $this->info->running=0;
			
			//fail the whole thing if there's an error
			if($er)
				return false;
				
            return $this->prep_vars($result);
        }
        else{ 
            $this->get_sql_error(false,'Error executing query');
            return false;
        };
    }
	
    function exists($table,$where=false){
        $this->info->running=1;
        $this->info->func='$db->exists';
        $this->info->vars=array('$table'=>$table,'$where'=>$where);
        
        $num=$this->get_count($table,$where);
        if($num > 0)
            return true;
        else
            return false;
    }
    
    // select and return only one row
    function select_one($table,$vals='*',$where=false,$extra=''){
        $this->info->running=1;
        $this->info->func='$db->select_one';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where,'$extra'=>$extra);
        
        $s=$this->select($table,$vals,$where,$extra);
		if($s===false)
			return false;
		else
			return $s[0];
    }
    
    // select function
    function select($table,$vals='*',$where=false,$extra=''){
        if($this->info->running==0){
            $this->info->running=1;
            $this->info->func='$db->select';
            $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where,'$extra'=>$extra);
        }
        // initialize the sql query
        $this->begin_query('SELECT');
        
        // add all the values to be selected 
        if(is_array($vals)){
            foreach($vals as $v)
                $this->sql.=$v.',';
            $this->sql=substr($this->sql,0,-1);
        }
        else
            $this->sql.=$vals;
        
        $this->sql.=' FROM '.$table;
        
        // build the WHERE portion of the query
        $this->build_where($where);
        
        $this->sql=$this->sql.' '.$extra;
        $ret=$this->query($this->sql,$this->replace);
        return $ret;
    }
    
    // insert
    function insert($table,$vals,$extra=''){
        $this->info->running=1;
        $this->info->func='$db->insert';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals);
        
        if($this->alternate_begin!=''){
            $this->begin_query($this->alternate_begin.' INTO '.$table.' SET');
            $this->alternate_begin='';
        }
        else
            $this->begin_query('INSERT INTO '.$table.' SET');
        
        // build the replace array and the query
        if(is_array($vals)){
            $c=count($this->replace);
            foreach($vals as $key=>$v){
                if(strstr($key,'/func')){
                    $this->sql.=str_replace('/func','',$key).'='.$v.', ';
                    unset($vals[$key]);
                }
                else{
                    $this->sql.=$key.'=:'.$c.', ';
                    if($v=='')
                        $this->replace[':'.$c]="";
                    else
                        $this->replace[':'.$c]=$v;
                    $c++;
                }
            }
            $this->sql=substr($this->sql,0,-2);
        }
        else{
            $this->sql.=' '.$vals;
        }
        $this->sql=$this->sql.' '.$extra;
        
        // run and return the query
        $ret=$this->query($this->sql,$this->replace);
        $id=$this->db->lastInsertId();
        $this->lastInsertId=$id;
        
        if($id)
            return $id;
        else
            return $ret;
    }
    
    function insert_ignore($table,$vals){
        $this->alternate_begin='INSERT IGNORE';
        $this->insert($table,$vals);
    }
    
    // update
    function update($table,$vals,$where=false){
        $this->info->running=1;
        $this->info->func='$db->update';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where);
        
        $this->begin_query('UPDATE '.$table.' SET');
        
        // build the replace array and the query
        if(is_array($vals)){
            $c=count($this->replace);
            foreach($vals as $key=>$v){
                if(strstr($key,'/func')){
                    $this->sql.=str_replace('/func','',$key).'='.$v.', ';
                    unset($vals[$key]);
                }
                else{
                    $this->sql.=$key.'=:'.$c.', ';
                    if($v=='')
                        $this->replace[':'.$c]="";
                    else
                        $this->replace[':'.$c]=$v;
                    $c++;
                }
            }
            $this->sql=substr($this->sql,0,-2);
        }
        else{
            $this->sql.=' '.$vals;
        }
        
        // build the WHERE portion of the query
        $this->build_where($where);
        
        // run and return the query
        return $this->query($this->sql,$this->replace);
    }
    function delete($table,$where){
        $this->info->running=1;
        $this->info->func='$db->delete';
        $this->info->vars=array('$table'=>$table,'$where'=>$where);
    
        $this->begin_query('DELETE FROM '.$table);
        
        // build the WHERE portion of the query
        $this->build_where($where);
        
        // run and return the query
        return $this->query($this->sql,$this->replace);
    }
    
    // get the number of records matching the requirements
    function get_count($table,$where=false){
        $this->info->running=1;
        $this->info->func='$db->get_count';
        $this->info->vars=array('$table'=>$table,'$where'=>$where);
        
        $this->begin_query("SELECT COUNT(*) c FROM ".$table);
        
        // double check the database connection object is working
        if(!$this->db){
            $this->add_error('000','Database connection failed.');
            return false;
        }   
        
        // build the WHERE portion of the query
        if($where)
            $this->build_where($where);
        
        // run and return the query
        $sth=$this->db->prepare($this->sql);
        
        if($sth){            
            if($this->replace)
                $sth->execute($this->replace);
            else
                $sth->execute();
            $this->get_sql_error($sth);
            
            //get and return the count
            $result=$sth->fetchAll(PDO::FETCH_OBJ);
            $this->info->running=0;
            return $result[0]->c;
        }
        else{
            $this->info->running=0;
            $this->get_sql_error($sth,'ERROR RETRIEVING get_count');
            return false;
        }
    }
    
    // gets value of requested column
    function get_value($table,$val,$where=false,$extra=''){
        $this->info->running=1;
        $this->info->func='$db->get_value';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where);
        
        // run query
        $o=$this->select($table,$val,$where,$extra);
        
        // convert first object in associative array to array
        if($o)
            $v=get_object_vars($o[0]);
        else
            return false;
        
        // return requested value
        return $v[$val];
    }
    
    // get the column names from a table
    function get_column_names($table){
        $this->info->running=1;
        $this->info->func='$db->get_columns';
        $this->info->vars=array('$table'=>$table);

        //get no records
        $this->sql='SELECT * FROM '.$table.' LIMIT 0';
        $ret=$this->db->query($this->sql);

        //no errors
        $er=$this->get_sql_error(array('000000'));
        
        //grab names
        for ($i = 0; $i < $ret->columnCount(); $i++) {
            $col = $ret->getColumnMeta($i);
            $fin[] = $col['name'];
        }
        return $fin;
    }
    
    //find any errors in the mysql statement
    private function get_sql_error($error,$error_statement=''){
    // find the fail
        if($error)
			if(is_object($error))
				$e=array('invalid','invalid','[invalid] ');
			else
				$e=array($error[0],$error[1],'['.$error[0].'] '.$error[2]);
        else
            $e=array('db error','',$error_statement);
        
        // catch any PDO errors and log them
        if($e[0]!='00000'){
            if($e[2])
                $this->add_error($e[0],$e[2]);
            else
                $this->add_error($e[0],'General Error upon execution');
        }
        
        if($this->debug || $this->debug_formatted)
            $this->_get_query($this->sql,$this->replace,$e);
        
        $this->info->func='';
        $this->info->vars=array();
		if($e[0]=='00000'){
			return false;
        }
		else{
            $this->error_count++;
			return true;
        }
    }
    
    //debugging function
    private function _get_query($query,$val,$er=0){
        if($val)
        foreach($val as $key=>$value){
            if(strtolower($value)=='null')
                $query=str_replace('='.$key,"='".$value."'",$query);
            else
                $query=str_replace('='.$key,"='".$value."'",$query);
        }
        if($er){
			if($er[0]!='00000'){
				$error=$er[2];
                $pass=false;
            }
			else{
				$error= 'no error';
                $pass=true;
            }
        }
        $html= '';
        $html.= '<strong>QUERY:</strong><br />'.$query;
        if(!$pass)
            $html.= '<br /><br /><strong>Error:</strong><pre>'.$error.'</pre>';
        
        $html.= '<br /><strong>Function used:</strong> '.$this->info->func.'<br />';
        $html.= '<br /><strong>Passed variables used:</strong><br /><pre>';
        $html.=print_r($this->info->vars,true);
        $html.= '</pre>';
        $html.= '<br /><strong>DB.php status:</strong><br /><pre>';
        $html.= '$db->sql: '.print_r($this->sql,true);
        $html.= '<br />$db->replace: '.print_r($this->replace,true);
        $html.= '</pre>';
        
        $this->_set_debug($this->info->func,$html,$pass);
    }
    private function _set_debug($title,$content,$pass=true){
        $html='';
        
        if(!$this->debug_formatted){
            $html="$title \r\n".strip_tags(str_replace(array('<br />','</div>','</h2>'),"\r\n",$content))."\r\n";
            $html.='-----------------------------------'."\r\n";
        }
        else{
            if($pass)
                $html.='<div style="background-color:#CCFFCC"><h2 style="background-color:#00C200;color:#fff;">'.$title.'</h2>';
            else
                $html.='<div style="background-color:#FFDDDD"><h2 style="background-color:#C20000;color:#fff;">'.$title.'</h2>';
            $html.=$content;
            $html.= '</div><hr />';
        }
        $this->debug_output=$html;
    }
	
	function _get_debug(){
		return $this->debug_output;
	}
	
	function _show_debug(){
		echo $this->debug_output;
	}
}


?>
