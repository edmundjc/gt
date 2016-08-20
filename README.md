# Group Tally
## gt.php
This was created to provide for a variety of potential input and output scenarios.

Use `php gt.php --help` for a list of command line options.

## gt.sh
This was created for absolute simplicity and speed, but assumes the following:
1. The input csv has no header.
2. The input columns are in the order of userID,userAge.
3. Output column order is unimportant.

use `./gt.sh /path/to/file\ with\ spaces.csv`