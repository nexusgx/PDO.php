PDO.php
===
I had been wanting to get away from the standard MySQL classes for awhile. I knew they were inherently less secure, but part of moving to something different i meant that I would have to change the way I work with the database.

I wanted security, but I also wanted it to be easier to plug in to applications. So I created a mysql class that connects using PDO and has some protections against injections.

Mind you, this is a work in progress, so if you find a security issue or any other sort of issue, please let me know.

Requires PHP 5.3 or later.

Usage
===
`````php
//assigning my default database information
define('DB_HOST','MyHost');
define('DB_USER','MyUsername');
define('DB_PASS','MyPassword');
define('DB_NAME','MyDatabase');

//instantiate class; if no array is provided, the defaults are used
$database = new DB(array('name'=>DB_NAME,'user'=>DB_USER,'password'=>DB_PASS,'host'=>DB_HOST));


//------------METHODS

//select all rows in a table
$database->select('MyDatabaseTable');

//select based on criteria
$database->select('MyDatabaseTable','*',array('id'=>1));

//select certain values based on criteria
$database->select('MyDatabaseTable','name,id',array('id'=>1));

//select based on criteria with a set order
$database->select('MyDatabaseTable','*',array('id'=>1),'ORDER BY name ASC');

//select single row based on criteria
$database->select_one('MyDatabaseTable','*',array('id'=>1));

//insert into table; returns primary key of that row
$options=array(
    id=>1,
    name=>'test'
);
$database->insert('MyDatabaseTable',$options);
    //or
$database->insert_ignore('MyDatabaseTable',$options);

//update table
$options=array(
    name=>'test_again'
);
$database->update('MyDatabaseTable',$options,array('id'=>1));

//delete from table
$database->delete('MyDatabaseTable',array(id=>1));

//get a count of rows matching the criteria
$database->get_count('MyDatabaseTable',array(id=>1));

//select a single value from a table
$database->get_value('MyDatabaseTable','name',array('id'=>1));

//get an array of the column names of a table
$database->get_column_names('MyDatabaseTable');

//run your own query; NOT AS SECURE AS USING THE OTHER FUNCTIONS
$database->query("SELECT * FROM MyDatabaseTable WHERE id=1");

//securely run your own query
$database->query("SELECT * FROM MyDatabaseTable WHERE id=:id",array(':id'=>1));

//reset your database connection; the parameters can be changed to new values if desired with a fallback to the defaults
$database->reconnect(array('name'=>DB_NAME,'user'=>DB_USER,'password'=>DB_PASS,'host'=>DB_HOST));


//------------PROPERTIES

//the last inserted id
$database->lastInsertId;

//the number of rows affected by the query
$database->rowsAffected=0;

//change the return type from 'object' to 'array'
$database->return_type='object';

//error count
$database->error_count;

//turn on debugging
$database->debug=true; //plain text
$database->debug_formatted=true; //html
`````

Using WHERE
===
To select records based on criteria, you have some options in how to select them. The $where array you provide to a method can be constructed in a few different ways to get the results you are looking for.

`````php
<?php

//use MySQL syntax in filtering your results instead of a direct value
//this option does bypass any PDO security in place, so please use carefully
$where=array('field1/func'=>'field2+1');

//search for null
$where=array('field1'=>null);

//use different MySQL comparative fields; currently works with !=<> and LIKE
$where=array('field1/%LIKE%'=>'value');

?>
`````
