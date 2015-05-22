<?php
/**
 * nexusgx/PDO.php - GitHub
 */
namespace nexusgx\PDO;

class Error
{
    // store errors
    private $errors = array();
    
    // add errors
    protected function addError($code, $str)
    {
        $this->errors[$code]=$str;
    }
    // get errors
    public function getErrors()
    {
        return $this->errors;
    }
}


class Db extends Error
{
    private $db;
    private $sql = '';
    private $replace = array();
    private $info; //for debug information
    
    public $debug = true;
    public $lastInsertId = '0';
    public $rowsAffected = 0;
    public $return_type = 'object';
    
    // initialize connection
    public function __construct()
    {
        try {
            $dsn = "mysql:dbname=".DB_NAME.";host=".DB_HOST;
            $this->db = new \PDO($dsn, DB_USER, DB_PASS);
            $this->db->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
            $this->db->query('SET NAMES GBK');
        } catch (\PDOException $e) {
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
        $this->info = new \stdClass();
    }
    
    // initialize the sql query
    private function beginQuery($type)
    {
        $this->errors = array();
        $this->sql = $type.' ';
        $this->replace = array();
    }

    private function buildWhere($where)
    {
        if (!empty($where)) {
            $this->sql.=" WHERE ";
            if (is_array($where)) {
                $c=count($this->replace);
                foreach ($where as $key => $w) {
                    // checks the array key for a slash and then the operator (eg. id/%LIKE%)
                    $op=substr(strstr($key, '/'), 1);
                    
                    // check for null variables
                    if (strtolower($w)=='null' || strtolower($w)=='!null' || $w===null) {
                        if ($op=='!=') {
                            $val='IS NOT NULL';
                        } else {
                            $val='IS NULL';
                        }
                        
                        $this->sql.=$key.' '.$val.' && ';
                    } // look for any comparitive symbols within the where array.
                    elseif ($op!='') {
                        $key=strstr($key, '/', true);
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
                        if ($op=='col') {
                            //ignore replacement if comparing to another table column
                            $this->sql.=$key.'='.$w.' && ';
                        } else {
                            $this->sql.=$key.$eq.':'.$c.' && ';
                            $this->replace[':'.$c]=$w;
                        }
                    } elseif (substr($w, 0, 1)=='%') {
                        // prep the query for PDO->prepare
                        $this->sql.=$key.'=%:'.$c.'% && ';
                        $this->replace[':'.$c]=$w;
                    } //check for comparative symbols
                    //RETAIN FOR BACKWARDS COMPATIBILITY
                    else {
                        if (substr($w, 0, 2)=='<=') {
                            $eq='<=';
                            $w=substr($w, 2);
                        } elseif (substr($w, 0, 2)=='>=') {
                            $eq='>=';
                            $w=substr($w, 2);
                        } elseif (substr($w, 0, 1)=='>') {
                            $eq='>';
                            $w=substr($w, 1);
                        } elseif (substr($w, 0, 1)=='<') {
                            $eq='<';
                            $w=substr($w, 1);
                        } elseif (substr($w, 0, 1)=='!') {
                            $eq='!=';
                            $w=substr($w, 1);
                        } else {
                            $eq='=';
                        }
                            
                        // prep the query for PDO->prepare
                        $this->sql.=$key.$eq.':'.$c.' && ';
                        $this->replace[':'.$c]=$w;
                    }
                    $c++;
                }
                $this->sql=substr($this->sql, 0, -4);
            } elseif (is_string($where)) {
                $this->sql.=$where;
            }
        }
    }
    
    // remove slashes from all retrieved variables
    private function prepVars($vars)
    {
        if (is_array($vars)) {
            foreach ($vars as $key => $value) {
                $ret[$key]=$this->prepVars($value);
            }
        } elseif (is_object($vars)) {
            $ret=new \stdClass();
            foreach ($vars as $key => $value) {
                $ret->$key=$this->prepVars($value);
            }
        } elseif (is_string($vars)) {
            $ret=stripslashes($vars);
        } else {
            $ret=$vars;
        }
        return $ret;
    }
    
    
    // general query function
    public function query($query, $vals = '')
    {
        if ($this->info->running==0) {
            $this->info->running=1;
            $this->info->func='$db->query';
            $this->info->vars=array('$query'=>$query,'$vals'=>$vals);
        }
        
    
        // double check the database connection object is working
        if (!$this->db) {
            $this->addError('000', 'Database connection failed.');
            return false;
        }
        // prep
        $sth=$this->db->prepare($query);
        
        // do it
        if ($sth) {
            if ($vals) {
                $pass=$sth->execute($vals);
            } else {
                $pass=$sth->execute();
            }
        } else {
            $this->info->running=0;
            $this->getSqlError($sth, 'Error executing query');
            return false;
        }         if ($pass) {
            if (substr($query, 0, 6)=="SELECT") {
                //grab
                if ($this->return_type=='object') {
                    $result=$sth->fetchAll(\PDO::FETCH_OBJ);
                } else {
                    $result=$sth->fetchAll(\PDO::FETCH_ASSOC);
                }
            } else {
                //return number of affected rows if not a SELECT query
                $result=true;
            }
            
            $this->rowsAffected=$sth->rowCount();
            
            //find any errors
            $er=$this->getSqlError($sth);
            
            $this->info->running=0;
            
            //fail the whole thing if there's an error
            if ($er) {
                return false;
            }
                
            return $this->prepVars($result);
        } else {
            return false;
        }
    }
    
    // select and return only one row
    public function selectOne($table, $vals = '*', $where = false, $extra = '')
    {
        $this->info->running=1;
        $this->info->func='$db->selectOne';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where,'$extra'=>$extra);
        
        $s=$this->select($table, $vals, $where, $extra);
        if ($s===false) {
            return false;
        } else {
            return $s[0];
        }
    }
    
    // select function
    public function select($table, $vals = '*', $where = false, $extra = '')
    {
        if ($this->info->running==0) {
            $this->info->running=1;
            $this->info->func='$db->select';
            $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where,'$extra'=>$extra);
        }
        // initialize the sql query
        $this->beginQuery('SELECT');
        
        // add all the values to be selected
        if (is_array($vals)) {
            foreach ($vals as $v) {
                $this->sql.=$v.',';
            }
            $this->sql=substr($this->sql, 0, -1);
        } else {
            $this->sql.=$vals;
        }
        
        $this->sql.=' FROM '.$table;
        
        // build the WHERE portion of the query
        $this->buildWhere($where);
        
        $this->sql=$this->sql.' '.$extra;
        $ret=$this->query($this->sql, $this->replace);
        return $ret;
    }
    
    // insert
    public function insert($table, $vals)
    {
        $this->info->running=1;
        $this->info->func='$db->insert';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals);
        
        $this->beginQuery('INSERT INTO '.$table.' SET');
        
        // build the replace array and the query
        $c=count($this->replace);
        foreach ($vals as $key => $v) {
            if (strstr($key, '/func')) {
                $this->sql.=str_replace('/func', '', $key).'='.$v.', ';
            } else {
                $this->sql.=$key.'=:'.$c.', ';
                $this->replace[':'.$c]=$v;
                $c++;
            }
        }         $this->sql=substr($this->sql, 0, -2);
        // run and return the query
        $ret=$this->query($this->sql, $this->replace);
        $id=$this->db->lastInsertId();
        $this->lastInsertId=$id;
        
        if ($id) {
            return $id;
        } else {
            return $ret;
        }
    }
    
    // update
    public function update($table, $vals, $where = false)
    {
        $this->info->running=1;
        $this->info->func='$db->update';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where);
        
        $this->beginQuery('UPDATE '.$table.' SET');
        
        // build the replace array and the query
        $c=count($this->replace);
        foreach ($vals as $key => $v) {
            if (strstr($key, '/func')) {
                $this->sql.=str_replace('/func', '', $key).'='.$v.', ';
            } else {
                $this->sql.=$key.'=:'.$c.', ';
                $this->replace[':'.$c]=$v;
                $c++;
            }
        }
        $this->sql=substr($this->sql, 0, -2);
        
        // build the WHERE portion of the query
        $this->buildWhere($where);
        
        // run and return the query
        return $this->query($this->sql, $this->replace);
    }

    public function delete($table, $where)
    {
        $this->info->running=1;
        $this->info->func='$db->delete';
        $this->info->vars=array('$table'=>$table,'$where'=>$where);
    
        $this->beginQuery('DELETE FROM '.$table);
        
        // build the WHERE portion of the query
        $this->buildWhere($where);
        
        // run and return the query
        return $this->query($this->sql, $this->replace);
    }
    
    // get the number of records matching the requirements
    public function getCount($table, $where = false)
    {
        $this->info->running=1;
        $this->info->func='$db->getCount';
        $this->info->vars=array('$table'=>$table,'$where'=>$where);
        
        $this->beginQuery("SELECT COUNT(*) c FROM ".$table);
        
        // double check the database connection object is working
        if (!$this->db) {
            $this->addError('000', 'Database connection failed.');
            return false;
        }
        
        // build the WHERE portion of the query
        if ($where) {
            $this->buildWhere($where);
        }
        
        // run and return the query
        $sth=$this->db->prepare($this->sql);
        
        if ($sth) {
            if ($this->replace) {
                $sth->execute($this->replace);
            } else {
                $sth->execute();
            }
            $this->getSqlError($sth);
            
            //get and return the count
            $result=$sth->fetchAll(\PDO::FETCH_OBJ);
            $this->info->running=0;
            return $result[0]->c;
        } else {
            $this->info->running=0;
            $this->getSqlError($sth, 'ERROR RETRIEVING getCount');
            return false;
        }
    }
    
    // gets value of requested column
    public function getValue($table, $val, $where = false)
    {
        $this->info->running=1;
        $this->info->func='$db->getValue';
        $this->info->vars=array('$table'=>$table,'$vals'=>$vals,'$where'=>$where);
        
        // run query
        $o=$this->select($table, $val, $where);
        
        // convert first object in associative array to array
        if ($o) {
            $v=get_object_vars($o[0]);
        }
        
        // return requested value
        return $v[$val];
    }
    
    // get the column names from a table
    public function getColumnNames($table)
    {
        $this->info->running=1;
        $this->info->func='$db->get_columns';
        $this->info->vars=array('$table'=>$table);
        // temporarily change return type to get an array
        $temp=$this->return_type;
        $this->return_type='array';
        
        $ret=$this->selectOne($table, '*', '', 'LIMIT 1');
        
        // change return type back
        $this->return_type=$temp;
        
        // grab names
        $fin=array();
        foreach ($ret as $key => $r) {
            $fin[]=$key;
        }
            
        return $fin;
    }

    //find any errors in the mysql statement
    private function getSqlError($sth, $error_statement = '')
    {
    // find the fail
        if ($sth) {
            $e=$sth->errorInfo();
        } else {
            $e=array('db error','',$error_statement);
        }
        
        // catch any PDO errors and log them
        if ($e[0]!='00000') {
            if ($this->debug) {
                if ($e[2]) {
                    echo '<strong>ERROR:</strong>: '.$e[2];
                } else {
                    echo '<strong>ERROR:</strong>: General Error';
                }
            } else {
                if ($e[2]) {
                    $this->addError($e[0], $e[2]);
                } else {
                    $this->addError($e[0], 'General Error upon execution');
                }
            }
        }
        if ($this->debug) {
            $this->getQuery($this->sql, $this->replace, $e);
        }
        
        $this->info->func='';
        $this->info->vars=array();
        if ($e[0]=='00000') {
            return false;
        } else {
            return true;
        }
    }

    //debugging function
    private function getQuery($query, $val, $er = 0)
    {
        $html= '';
        if ($val) {
            foreach ($val as $key => $value) {
                if (strtolower($value)=='null') {
                    $query=str_replace($key, "'".$value."'", $query);
                } else {
                    $query=str_replace($key, "'".$value."'", $query);
                }
            }
        }
        $html.= '<strong>QUERY:</strong><br />'.$query;
        if ($er) {
            $html.= '<br /><br /><strong>Raw error:</strong><pre>';
            if ($er[0]!='00000') {
                $html.=print_r($er, true);
            } else {
                $html.= 'no error';
            }
            $html.= '</pre>';
        }
        $html.= '<br /><strong>Function used:</strong> '.$this->info->func.'<br />';
        $html.= '<br /><strong>Passed variables used:</strong><br /><pre>';
        $html.=print_r($this->info->vars, true);
        $html.= '</pre>';
        $html.= '<br /><strong>DB.php status:</strong><br /><pre>';
        $html.= '$db->sql: '.print_r($this->sql, true);
        $html.= '<br />$db->replace: '.print_r($this->replace, true);
        $html.= '</pre>';
        $this->showDebug($this->info->func.' error', $html);
    }

    private function showDebug($title, $content)
    {
        $html= '<p>';
        $html.='<h2>'.$title.'</h2>';
        $html.=$content;
        $html.= '</p><hr />';
        echo $html;
    }
}
