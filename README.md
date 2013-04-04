PDO
===
I had been wanting to get away from the standard MySQL classes for awhile. I knew they were inherently less secure, but part of moving to something different i meant that I would have to change the way I work with the database.

I wanted security, but I also wanted it to be easier to plug in to applications. So I created a mysql class that connects using PDO and has some protections against injections.

Mind you, this is a work in progress, so if you find a security issue or any other sort of issue, please let me know.

Usage
===
`````php
//assigning my database information
define('DB_HOST','MyHost');
define('DB_USER','MyUsername');
define('DB_PASS','MyPassword');
define('DB_NAME','MyDatabase');

//instantiate class
$database = new DB();


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

//run your own query; NOT AS SECURE AS USING THE OTHER FUNCTIONS
$database->query("SELECT * FROM MyDatabaseTable WHERE id=1");


//------------PROPERTIES

//the last inserted id
$database->lastInsertId;

//turn on debugging
$database->debug=true;
`````
