<?php
/**
 * Created by PhpStorm.
 * User: jcarpenter
 * Date: 8/17/16
 * Time: 5:38 PM
 */

const DEFAULT_COLUMNS = "user ID,user age"; //Layout of the CSV
const DEFAULT_GROUP_BY = "user age";

const DEFAULT_OUTPUT_FORMAT = "text"; //text|json

$inputFile = false;
$outputFile = false;
$fileHasHeaderRow = false;
$columnString = DEFAULT_COLUMNS;
$groupBy = DEFAULT_GROUP_BY;
$outputFormat = DEFAULT_OUTPUT_FORMAT;
$ignoreBadRows = false;

array_shift($argv); //Discard script name

//Loop through the arguments
while ($arg = array_shift($argv)) {
	switch ($arg) {

		case '-i':
			$inputFile = array_shift($argv);
			if (!file_exists($inputFile)) {
				echo "Input file $inputFile does not exist.";
				Quit();
			}
			break;

		case '-o':
			$outputFile = array_shift($argv);
			$lastChar = substr($outputFile, strlen($outputFile) - 1, 1);
			if (!file_exists(dirname($outputFile))) {
				echo "Output directory $outputFile does not exist.";
				Quit();
			}
			if ($lastChar == "\\" || $lastChar == "/") {
				echo "Output file cannot be a directory";
				Quit();
			}
			if (file_exists($outputFile)) {
				echo "File $outputFile already exists.  Overwrite? y/N: ";
				$handle = fopen("php://stdin", "r");
				$line = strtolower(trim(fgets($handle)));
				if ($line != 'y') {
					echo "Aborted";
					Quit();
				}
			}
			break;

		case '-h':
			$fileHasHeaderRow = true;
			break;

		case '-c':
			$columnString = array_shift($argv);
			break;

		case '-g':
			$groupBy = trim(array_shift($argv));
			break;

		case '-b':
			$ignoreBadRows = true;
			break;

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
			echo "                               (Default text)\r\n";
			Quit();
			break;

		default:
			echo "Unknown argument \"$arg\".  Try --help";
			Quit();
			break;

	}
}

$start = microtime(true);

if ($inputFile) {
	$handle = fopen($inputFile, 'r'); //Open the file for reading.

	if ($groupBy) {

		$lineNumber = 0;
		if ($fileHasHeaderRow) {
			$lineNumber++;
			$columnString = fgets($handle);
		}

		if ($columnString) {
			$gt = new GT($columnString, $ignoreBadRows);

			while (($line = fgets($handle)) !== false) {
				$lineNumber++;
				$gt->Add($line, $lineNumber);
			}

			if ($gt->ColumnCount) {
				$result = $gt->GroupCount($groupBy);

				if(!empty($result)) {

					$output = "";

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

					if ($outputFile) {
						file_put_contents($outputFile, $output);
					}
					else echo "\r\n$output\r\n";

					$errorCount = $gt->ErrorCount();

					echo "\r\nProcessed ".number_format($lineNumber)." records in "
						.number_format(microtime(true) - $start, 2)." seconds.";

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
		else echo "Empty file.";
	}
	else echo "Please specify a column by which to group your tally.";
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
	 * @param string $columnString
	 * @param bool $ignoreBadRows
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
	 * @param string $rowString
	 * @param string $lineNumber
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
				}
			}
			ksort($result);
			return $result;
		}
		else {
			echo "Column $groupBy not found.";
			Quit();
		}
	}

	/**
	 * @return int
	 */
	function ErrorCount() { return count($this->Errors); }
}

/**
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