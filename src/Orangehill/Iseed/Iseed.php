<?php namespace Orangehill\Iseed;

use Illuminate\Filesystem\Filesystem;

class Iseed {

	public function __construct(Filesystem $filesystem = null)
	{
		$this->files = $filesystem ?: new Filesystem;
	}

	/**
	 * Generates a seed file.
	 * @param  string  $table
	 * @return void
	 * @throws Orangehill\Iseed\TableNotFoundException
	 */
	public function generateSeed($table)
	{
		// Check if table exists
		if (!$this->hasTable($table)) throw new TableNotFoundException("Table $table was not found.");

		// Get the row count
		$rowCount = $this->getRowCount($table);

		// Get the column count
		$columnCount = $this->getColumnCount($table);

		// Generate class name
		$className = $this->generateClassName($table);

		// Get a seed folder path
		$seedPath = $this->getSeedPath();

		// Get a app/database/seeds path
		$seedsPath = $this->getPath($className, $seedPath);

		$seedContent = null;

		if ($rowCount * $columnCount > 2000)
		{
			$stdout = null;
			exec("which mysqldump", $stdout);
			$mysqlDumpPath = $stdout[0];

			$sqlPath = str_replace(".php", ".sql.gz", $seedsPath);
			$sqlPath = str_replace(basename($sqlPath), "dumps/" . basename($sqlPath), $sqlPath);

			if (!file_exists(dirname($sqlPath)))
				mkdir(dirname($sqlPath));

			$default_db = \Config::get('database.default');
			$connections = \Config::get('database.connections');
			$dbconf = $connections[$default_db];

			$cmd = "{$mysqlDumpPath} --no-create-db --no-create-info --user={$dbconf['username']} --password={$dbconf['password']} --host={$dbconf['host']} --port={$dbconf['port']} {$dbconf['database']} {$table} | gzip > '{$sqlPath}'";

			$stdout = null;
			exec($cmd, $stdout, $retval);

			$errors = false;

			if ($retval != 0) {
				echo "mysqldump command failed!\ncmd: " . $cmd . "\n";
				$errors = true;
			}

			if (!empty($stdout)){
			    foreach ($stdout as $line){
			        echo $line . "\n";
			    }

			    $errors = true;
			}

			if ($errors)
				return false;

			// Get template for a seed file contents
			$stub = $this->files->get($this->getStubPath() . '/seed_mysqldump.stub');

			// Get a populated stub file
			$seedContent = $this->populateStubMysqlDump($className, $stub, $table, basename($sqlPath));
		}else{
			// Get the data
			$data = $this->getData($table);

			// Repack the data
			$dataArray = $this->repackSeedData($data);

			// Get template for a seed file contents
			$stub = $this->files->get($this->getStubPath() . '/seed.stub');

			// Get a populated stub file
			$seedContent = $this->populateStub($className, $stub, $table, $dataArray);
		}

		if ($seedContent != "")
		{
			// Save a populated stub
			$this->files->put($seedsPath, $seedContent);
		}else{
			return false;
		}


		// Update the DatabaseSeeder.php file
		return $this->updateDatabaseSeederRunMethod($className) !== false;

        return false;

	}

	/**
	 * Get a seed folder path
	 * @return string
	 */
	public function getSeedPath()
	{
		return app_path() . \Config::get('iseed::path');
	}

	/**
	 * Get the Data
	 * @param  string $table
	 * @return Array
	 */
	public function getData($table)
	{
		return \DB::table($table)->get();
	}

	/**
	 * Get the Data Row Count
	 * @param  string $table
	 * @return int
	 */
	public function getRowCount($table)
	{
		return \DB::table($table)->count();
	}

	/**
	 * Get the Data Column Count
	 * @param  string $table
	 * @return int
	 */
	public function getColumnCount($table)
	{
		$pdo = \DB::connection()->getPdo();
		$sth = $pdo->prepare("SELECT * FROM {$table} LIMIT 0");
		$sth->execute();
		return $sth->columnCount();
	}

	/**
	 * Repacks data read from the database
	 * @param  object $data
	 * @return array
	 */
	public function repackSeedData($data)
	{
    	$dataArray = array();
    	if(is_array($data)) {
			foreach ($data as $row) {
				$rowArray = array();
				foreach($row as $columnName => $columnValue) {
					 $rowArray[$columnName] = $columnValue;
				}
				$dataArray[] = $rowArray;
			}
    	}
		return $dataArray;
	}

	/**
	 * Checks if a database table exists
	 * @return boolean
	 */
	public function hasTable($table)
	{
		return \Schema::hasTable($table);
	}

	/**
	 * Generates a seed class name (also used as a filename)
	 * @param  string  $table
	 * @return string
	 */
	public function generateClassName($table)
	{
		$tableString = '';
		$tableName = explode('_', $table);
		foreach($tableName as $tableNameExploded) {
			$tableString .= ucfirst($tableNameExploded);
		}
		return ucfirst($tableString) . 'TableSeeder';
	}

	/**
	 * Get the path to the stub file.
	 * @return string
	 */
	public function getStubPath()
	{
		return __DIR__ . '/Stubs';
	}

	/**
	 * Populate the place-holders in the seed stub.
	 * @param  string  $class
	 * @param  string  $stub
	 * @param  string  $table
	 * @param  string  $data
	 * @return string
	 */
	public function populateStub($class, $stub, $table, $data, $chunkSize = null)
	{
        $chunkSize = $chunkSize ?: \Config::get('iseed::chunk_size');
        $inserts = '';
        $chunks = array_chunk($data, $chunkSize);
        foreach ($chunks as $chunk) {
            $inserts .= sprintf("\n\t\t\DB::table('%s')->insert(%s);", $table, $this->prettifyArray($chunk));
        }

		$stub = str_replace('{{class}}', $class, $stub);

		if (!is_null($table)) {
			$stub = str_replace('{{table}}', $table, $stub);
		}

		$stub = str_replace('{{insert_statements}}', $inserts, $stub);

		return $stub;
	}

	/**
	 * Populate the place-holders in the seed stub.
	 * @param  string  $class
	 * @param  string  $stub
	 * @param  string  $table
	 * @param  string  $data
	 * @return string
	 */
	public function populateStubMysqlDump($class, $stub, $table, $sqlFile)
	{
		$stub = str_replace('{{class}}', $class, $stub);

		if (!is_null($table)) {
			$stub = str_replace('{{table}}', $table, $stub);
		}

		$stub = str_replace('{{sql_file}}', $sqlFile, $stub);

		return $stub;
	}

	/**
	 * Create the full path name to the seed file.
	 * @param  string  $name
	 * @param  string  $path
	 * @return string
	 */
	public function getPath($name, $path)
	{
		return $path . '/' . $name . '.php';
	}

	/**
	 * Prettify a var_export of an array
	 * @param  array  $array
	 * @return string
	 */
	protected function prettifyArray($array)
	{
		$content = var_export($array, true);

		$lines = explode("\n", $content);

		$inString = false;
		$tabCount = 3;
		for($i = 1; $i < count($lines); $i++) {
			$lines[$i] = ltrim($lines[$i]);

			//Check for closing bracket
			if(strpos($lines[$i], ')') !== false) {
				$tabCount--;
			}

			//Insert tab count
			if ($inString === false) {
				for($j = 0; $j < $tabCount; $j++) {
					$lines[$i] = substr_replace($lines[$i], "\t", 0, 0);
				}
			}

			for($j = 0; $j < strlen($lines[$i]); $j++) {
				//skip character right after an escape \
				if($lines[$i][$j] == '\\') {
					$j++;
				}
				//check string open/end
				else if($lines[$i][$j] == '\'') {
					$inString = !$inString;
				}
			}

			//check for openning bracket
			if(strpos($lines[$i], '(') !== false) {
				$tabCount++;
			}
		}

		$content = implode("\n", $lines);

		return $content;
	}

    /**
    * Updates the DatabaseSeeder file's run method (kudoz to: https://github.com/JeffreyWay/Laravel-4-Generators)
    * @param  string  $className
    * @return bool
    */
    public function updateDatabaseSeederRunMethod($className)
    {
    	$databaseSeederPath = app_path() . \Config::get('iseed::path') . '/DatabaseSeeder.php';

        $content = $this->files->get($databaseSeederPath);
        if(strpos($content, "\$this->call('{$className}')")===false)
			$content = preg_replace("/(run\(\).+?)}/us", "$1\t\$this->call('{$className}');\n\t}", $content);

        return $this->files->put($databaseSeederPath, $content) !== false;
        return false;
    }

}
