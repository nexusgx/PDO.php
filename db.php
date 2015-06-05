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
    
    public $debug=false;
    public $lastInsertId='0';
	public $rowsAffected=0;
    public $return_type='object';
    
    
    // initialize connection
    function __construct(){
        try {
            $dsn="mysql:dbname=".DB_NAME.";host=".DB_HOST;
            $this->db = new PDO($dsn, DB_USER, DB_PASS);
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->query('SET NAMES GBK');
        } catch (PDOException $e) {
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
        $this->info=new stdClass();
    }
    
    // initialize the sql query
    private function begin_query($type){
        $this->errors=array();
        $this->sql=$type.' ';
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
            $this->get_sql_error($sth,'Error executing query');
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
            $er=$this->get_sql_error($sth);
            
            $this->info->running=0;
			
			//fail the whole thing if there's an error
			if($er)
				return false;
				
            return $this->prep_vars($result);
        }
        else return false;
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
    function insert($table,$vals){
        $this->info->running=1;
        $this->info->func='$db->insert';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals);
        
        $this->begin_query('INSERT INTO '.$table.' SET');
        
        // build the replace array and the query
        $c=count($this->replace);
        foreach($vals as $key=>$v){
			if(strstr($key,'/func')){
				$this->sql.=str_replace('/func','',$key).'='.$v.', ';
			}
			else{
				$this->sql.=$key.'=:'.$c.', ';
				$this->replace[':'.$c]=$v;
				$c++;
			}
        }
        $this->sql=substr($this->sql,0,-2);
        // run and return the query
        $ret=$this->query($this->sql,$this->replace);
        $id=$this->db->lastInsertId();
        $this->lastInsertId=$id;
        
        if($id)
            return $id;
        else
            return $ret;
    }
    
    // update
    function update($table,$vals,$where=false){
        $this->info->running=1;
        $this->info->func='$db->update';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where);
        
        $this->begin_query('UPDATE '.$table.' SET');
        
        // build the replace array and the query
        $c=count($this->replace);
        foreach($vals as $key=>$v){
			if(strstr($key,'/func')){
				$this->sql.=str_replace('/func','',$key).'='.$v.', ';
			}
			else{
				$this->sql.=$key.'=:'.$c.', ';
				$this->replace[':'.$c]=$v;
				$c++;
			}
        }
        $this->sql=substr($this->sql,0,-2);
        
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
    function get_value($table,$val,$where=false){
        $this->info->running=1;
        $this->info->func='$db->get_value';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where);
        
        // run query
        $o=$this->select($table,$val,$where);
        
        // convert first object in associative array to array
        if($o)
            $v=get_object_vars($o[0]);
        
        // return requested value
        return $v[$val];
    }
    
    // get the column names from a table
    function get_column_names($table){
        $this->info->running=1;
        $this->info->func='$db->get_columns';
        $this->info->vars=array('$table'=>$table);

        // temporarily change return type to get an array
        $temp=$this->return_type;
        $this->return_type='array';
        
        $ret=$this->select_one($table,'*','','LIMIT 1');
        
        // change return type back
        $this->return_type=$temp;
        
        // grab names
        $fin=array();
        foreach($ret as $key=>$r)
            $fin[]=$key;
            
        return $fin;
    }
    
    //find any errors in the mysql statement
    private function get_sql_error($sth,$error_statement=''){
    // find the fail
        if($sth)
            $e=$sth->errorInfo();
        else
            $e=array('db error','',$error_statement);
        
        // catch any PDO errors and log them
        if($e[0]!='00000'){
            if($this->debug){
                if($e[2])
                    echo '<strong>ERROR:</strong>: '.$e[2];
                else
                    echo '<strong>ERROR:</strong>: General Error';
            }
            else{
                if($e[2])
                    $this->add_error($e[0],$e[2]);
                else
                    $this->add_error($e[0],'General Error upon execution');
            }
        }
        
        if($this->debug)
            $this->_get_query($this->sql,$this->replace,$e);
        
        $this->info->func='';
        $this->info->vars=array();
		if($e[0]=='00000')
			return false;
		else
			return true;
    }
    
    //debugging function
    private function _get_query($query,$val,$er=0){
        $html= '';
        if($val)
        foreach($val as $key=>$value){
            if(strtolower($value)=='null')
                $query=str_replace($key,"'".$value."'",$query);
            else
                $query=str_replace($key,"'".$value."'",$query);
        }
        $html.= '<strong>QUERY:</strong><br />'.$query;
        if($er){
            $html.= '<br /><br /><strong>Raw error:</strong><pre>';
			if($er[0]!='00000')
				$html.=print_r($er,true);
			else
				$html.= 'no error';
            $html.= '</pre>';
        }
        $html.= '<br /><strong>Function used:</strong> '.$this->info->func.'<br />';
        $html.= '<br /><strong>Passed variables used:</strong><br /><pre>';
        $html.=print_r($this->info->vars,true);
        $html.= '</pre>';
        $html.= '<br /><strong>DB.php status:</strong><br /><pre>';
        $html.= '$db->sql: '.print_r($this->sql,true);
        $html.= '<br />$db->replace: '.print_r($this->replace,true);
        $html.= '</pre>';
        $this->_show_debug($this->info->func.' error',$html);
    }
    function _show_debug($title,$content){
        $html= '<p>';
        $html.='<h2>'.$title.'</h2>';
        $html.=$content;
        $html.= '</p><hr />';
        echo $html;
    }
}

?>
