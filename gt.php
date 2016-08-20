<?php
/**
 * Created by PhpStorm.
 * User: jcarpenter
 * Date: 8/17/16
 * Time: 5:38 PM
 */

//Default config
const DEFAULT_COLUMNS = "user ID,user age"; //Layout of the CSV
const DEFAULT_GROUP_BY = "user age";
const DEFAULT_OUTPUT_FORMAT = "csv"; //csv|text|json

//Setup our variables
$inputFilePath = false;
$outputFilePath = false;
$fileHasHeaderRow = false;
$columnString = DEFAULT_COLUMNS;
$groupBy = DEFAULT_GROUP_BY;
$outputFormat = DEFAULT_OUTPUT_FORMAT;
$ignoreBadRows = false;

array_shift($argv); //Discard script name

//Loop through the arguments
while ($arg = array_shift($argv)) {
	switch ($arg) {

		//Input file path
		case '-i':
			$inputFilePath = array_shift($argv);
			if (!file_exists($inputFilePath)) {
				echo "Input file $inputFilePath does not exist.";
				Quit();
			}
			break;

		//Output file path
		case '-o':
			$outputFilePath = array_shift($argv);

			//Does the directory exist?
			$lastChar = substr($outputFilePath, strlen($outputFilePath) - 1, 1);
			if (!file_exists(dirname($outputFilePath))) {
				echo "Output directory $outputFilePath does not exist.";
				Quit();
			}

			//Does it point to a directory?  We can't write to a directory.
			if ($lastChar == "\\" || $lastChar == "/") {
				echo "Output file cannot be a directory";
				Quit();
			}

			//Does the file already exist?  Overwrite?
			if (file_exists($outputFilePath)) {
				echo "File $outputFilePath already exists.  Overwrite? y/N: ";
				$handle = fopen("php://stdin", "r");
				$line = strtolower(trim(fgets($handle)));
				if ($line != 'y') {
					echo "Aborted";
					Quit();
				}
			}
			break;

		//File contains header in first row
		case '-h':
			$fileHasHeaderRow = true;
			break;

		//Force custom column string
		case '-c':
			$columnString = array_shift($argv);
			break;

		//Designate group-by column
		case '-g':
			$groupBy = trim(array_shift($argv));
			break;

		case '-b':
			$ignoreBadRows = true;
			break;

		//Desired output format
		case '-f':
			$format = strtolower(array_shift($argv));
			switch ($format) {
				case 'text':
				case 'csv':
				case 'json':
					$outputFormat = $format;
					break;

				default:
					echo "Invalid output format $format";
					Quit();
			}
			break;

		case '--help':
			HELPME();
			Quit();
			break;

		default:
			echo "Unknown argument \"$arg\".  Try --help";
			Quit();
			break;

	}
}

//The clock is ticking.
$start = microtime(true);

if ($inputFilePath) {
	$handle = fopen($inputFilePath, 'r'); //Open the file for reading.

	if($handle) {
		if ($groupBy) {

			$lineNumber = 0; //Used to provide feedback if a bad row is encountered.

			//Record the first line if we're expecting a header row.
			if ($fileHasHeaderRow) {
				$lineNumber++;
				$columnString = fgets($handle);
			}

			if ($columnString) {
				$gt = new GT($columnString, $ignoreBadRows);

				//Read all remaining lines onto the sorting object
				while (($line = fgets($handle)) !== false) {
					$lineNumber++;
					$gt->Add($line, $lineNumber);
				}


				if ($gt->RecordCount()) {
					$result = $gt->GroupCount($groupBy); //DO IT!

					if (!empty($result)) {

						$output = "";

						//Arrange for output
						switch ($outputFormat) {
							case "text":
								$array[] = array($groupBy, 'count');
								foreach ($result as $key => $count) $array[] = array($key, $count);
								$output = MonospaceColumns($array);
								break;

							case "csv":
								$array[] = array($groupBy, 'count');
								foreach ($result as $key => $count) $array[] = array($key, $count);
								$output = ArrayToCSV($array);
								break;

							case "json":
								$json = array();
								foreach ($result as $key => $count) $json[] = array($groupBy => $key, 'count' => $count);
								$output = json_encode($json);
								break;
						}

						//Handle desired output method
						if ($outputFilePath) {
							file_put_contents($outputFilePath, $output);
						}
						else echo "\r\n$output\r\n";

						$errorCount = $gt->ErrorCount();

						//How long did it take?
						echo "\r\nProcessed ".number_format($lineNumber)." records in "
							.number_format(microtime(true) - $start, 2)." seconds.";

						//Did anything go wrong?
						if ($errorCount) {
							echo "\r\nEncountered ".$errorCount." bad line".($errorCount == 1 ? '' : 's')
								.". List line numbers? y/N: ";
							$handle = fopen("php://stdin", "r");
							$line = strtolower(trim(fgets($handle)));
							if ($line == 'y') {
								foreach ($gt->Errors as $error) echo "$error\r\n";
							}
						}
					}
					else echo "Empty result.";
				}
				else echo "No rows in file.";
			}
			else echo "Empty file, or bad column designation.";
		}
		else echo "Please specify a column by which to group your tally.";
	}
	else echo "Could not open $inputFilePath for writing.";
}
else echo "No input file specified.";

Quit();

/**
 * Class GT
 * Group Tally class.  Feed it a bunch of records, and it will produce a count of records
 * grouped by a column of your choosing.
 */
class GT {
	/** @var array[] */
	private $list = array();
	/** @var string[] */
	private $columns;
	/** @var int */
	public $ColumnCount;
	/** @var bool */
	public $IgnoreBadRows = false;
	/** @var int[] */
	public $Errors = array();

	/**
	 * GT constructor.
	 * @param string $columnString  A CSV-formatted string indicating column titles
	 * @param bool $ignoreBadRows  Whether or not to break on a bad row
	 */
	public function __construct($columnString, $ignoreBadRows = false) {
		$columns = ($columnString && is_string($columnString)) ? str_getcsv($columnString) : false;
		if ($columns && is_array($columns) && count($columns) >= 2) {
			$this->columns = array_values($columns);
			$this->ColumnCount = count($this->columns);
			$this->IgnoreBadRows = $ignoreBadRows;
		}
		else {
			echo "Bad column identifier: $columnString";
			Quit();
		}
	}

	/**
	 * @param string $rowString  A line from a CSV file
	 * @param string $lineNumber  For reporting bad rows
	 */
	public function Add($rowString, $lineNumber = 'unknown') {
		$rowString = trim($rowString);
		if ($rowString) {
			$row = str_getcsv($rowString);
			if ($row) {
				if (is_array($row) && count($row) >= $this->ColumnCount) $this->list[] = array_values($row);
				else if (!$this->IgnoreBadRows) {
					echo "Bad row on line $lineNumber";
					Quit();
				}
				else $this->Errors[] = $lineNumber;
			}
			else $this->Errors[] = $lineNumber;
		} //Just ignoring empty rows
	}

	/**
	 * Do the thing.  Generate array of counts, grouped by the requested column
	 * @param $groupBy
	 * @return array|bool
	 */
	function GroupCount($groupBy) {
		if (($columnKey = array_search($groupBy, $this->columns)) !== false) {
			$result = array();
			foreach ($this->list as $item) {
				if (isset($item[$columnKey])) {
					//The Magic
					if (isset($result[$item[$columnKey]])) $result[$item[$columnKey]]++;
					else $result[$item[$columnKey]] = 1;
					//End The Magic
				}
			}
			ksort($result);
			return $result;
		}
		else {
			echo "Column $groupBy not found.";
			Quit();

			//My IDE is complaining that not all code paths return values.
			//So here's an unreachable one.  You're welcome, PHPStorm.
			return false;
		}
	}

	/** @return int */
	function RecordCount() { return count($this->list); }

	/** @return int */
	function ErrorCount() { return count($this->Errors); }
}

/**
 * Draws a delightfully spaced text table
 * @param array[] $table
 * @return bool|string
 */
function MonospaceColumns($table) {
	if (is_array($table)) {
		$widths = array();
		foreach ($table as $row) {
			if (is_array($row)) {
				foreach (array_values($row) as $columnID => $item) {
					$widths[$columnID] = (isset($widths[$columnID]))
						? max($widths[$columnID], strlen($item))
						: strlen($item);
				}
			}
		}

		$result = "";
		foreach ($table as $row) {
			if (is_array($row)) {
				foreach (array_values($row) as $columnID => $item) {
					$result .= "| ".str_repeat(' ', $widths[$columnID] - strlen($item)).$item." ";
				}
			}
			$result .= "|\r\n";
		}
		return $result;
	}
	else return false;
}

/**
 * Converts a jagged 2 dimensional array into a CSV
 * @param array[] $array
 * @param string $fieldDelimiter
 * @param string $textDelimiter
 * @param string $nl
 * @return string
 */
function ArrayToCSV($array, $fieldDelimiter = ",", $textDelimiter = "\"", $nl = "\r\n") {
	$result = "";
	if (is_array($array)) {
		foreach ($array as $row) $result .= ArrayToCSVLine($row, $fieldDelimiter, $textDelimiter).$nl;
	}
	return $result;
}

/**
 * Converts an array of strings into a single CSV line
 * @param array $array
 * @param string $fieldDelimiter
 * @param string $textDelimiter
 * @return string
 */
function ArrayToCSVLine($array, $fieldDelimiter = ",", $textDelimiter = "\"") {
	//No built-in CSV escape function so we do it here
	foreach ($array as $id => $value) {
		if (strstr($value, $textDelimiter) || strstr($value, $fieldDelimiter))
			$array[$id] = $textDelimiter.str_replace($textDelimiter, $textDelimiter.$textDelimiter, $value)
				.$textDelimiter;
	}
	return implode($fieldDelimiter, $array);
}

//Adds a few lines before exiting the app.  Handy for CLI.
function Quit() {
	echo "\r\n\r\n";
	exit;
}

function HELPME() {
	echo "\r\nUsage: php gt.php [OPTIONS] -i INPUTFILE -g GROUPBY\r\n";
	echo "  REQUIRED:\r\n";
	echo "      -i INPUTFILE        Path to the input file\r\n";
	echo "  OPTIONAL:\r\n";
	echo "      -c COLUMNS          Comma separated columns\r\n";
	echo "                              (Default \"user ID, user age\")\r\n";
	echo "      -g GROUPBY          Title of the 'group by' column\r\n";
	echo "                              (Default \"user age\")\r\n";
	echo "      -o                  Path to the output file\r\n";
	echo "                              (Default will print to stdout)\r\n";
	echo "      -h                  Header of CSV contains column names\r\n";
	echo "                               (Default off)\r\n";
	echo "      -b                  Ignore bad lines in the CSV\r\n";
	echo "                               (Default off)\r\n";
	echo "      -f text|csv|json    Output format\r\n";
	echo "                               (Default csv)\r\n";
}