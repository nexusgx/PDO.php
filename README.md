PDO
===
I had been wanting to get away from the standard MySQL classes for awhile. I knew they were inherently less secure, but part of moving to something different i meant that I would have to change the way I work with the database.

I wanted security, but I also wanted it to be easier to plug in to applications. So I created a mysql class that connects using PDO and has some protections against injections.

Mind you, this is a work in progress, so if you find a security issue or any other sort of issue, please comment so this can get updated.
