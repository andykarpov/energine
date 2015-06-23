<?php
/**
 * @file
 * DBStructureInfo.
 *
 * It contains the definition to:
 * @code
final class DBStructureInfo;
@endcode
 *
 * @author d.pavka@gmail.com
 * @copyright Energine 2010
 *
 * @version 1.0.0
 */
namespace Energine\share\gears;
/**
 * Data base structure information.
 *
 * @code
final class DBStructureInfo;
@endcode
 *
 * It can holds an information in cache.
 *
 * @final
 */
class DBStructureInfo extends Object {
	/**
	 * Structure information.
	 *
	 * Structure:
	 * @code
	array(
    $tableName => false| null | array(
        $coulmnName => array(
            $columnPropName => $columnPropValue
        )
    )
)
@endcode
	 *
	 * @var array $structure
	 */
	private $structure;

	/**
	 * PDO (PHP Data Objects).
	 *
	 * @var \PDO $pdo
	 */
	private $pdo;

	/**
	 * @param \PDO $pdo PDO instance.
	 */
	public function __construct(\PDO $pdo) {
		$this->pdo = $pdo;
		$mc = E()->getCache();
		if ($mc->isEnabled()) {
			if (!($this->structure = $mc->retrieve(Cache::DB_STRUCTURE_KEY))) {
				$mc->store(Cache::DB_STRUCTURE_KEY, $this->structure = $this->collectDBInfo());
			}
		}
	}

	/**
	 * Collect the table structure information of all tables in the DB.
	 *
	 * This can be called only by using cache.
	 *
	 * @return array
	 */
	private function collectDBInfo() {
        $result = array();
		$res = $this->pdo->query('/*ms=slave*/SHOW TABLES');
		if ($res) {
			while ($tableName = $res->fetchColumn()) {
				$result[$tableName] = $this->getTableMeta($tableName);
			}
		}

		return $result;
	}

	/**
	 * Check if some table exist.
	 *
	 * @param  string $tableName Table name.
	 * @return bool
	 */
	public function tableExists($tableName) {
		$result = false;

		//Если не существует в кеше
		if (!isset($this->structure[$tableName])) {

			$FQTableName = QAL::getFQTableName($tableName, true);

			$query = '/*ms=slave*/SHOW TABLES '.((sizeof($FQTableName) == 2) ? ' FROM `'.reset($FQTableName).'` ' : '').' LIKE \''.end($FQTableName).'\'';

			//если существует в списке таблиц
			if ($this->pdo->query($query) && $this->pdo->query($query)->rowCount()) {
				$result = true;
                $this->structure[$tableName] = array();
            }
            else {
				//в списке таблиц  - не значится
				$this->structure[$tableName] = false;
			}
		} else {
			$result = is_array($this->structure[$tableName]);

		}

		return $result;
	}

	/**
	 * Get table meta data.
	 *
	 * @param string $tableName Table name.
	 * @return mixed
	 */
	public function getTableMeta($tableName) {
		if (!isset($this->structure[$tableName]) ||
                ($this->structure[$tableName] === array())) {
			//@todo тут все нужно свести только к вызову метода анализа таблицы

			$this->structure[$tableName] = $this->analyzeTable($tableName);
			if (empty($this->structure[$tableName])) {
				//Скорее всего это view
				//анализируем его
				$this->structure[$tableName] = $this->analyzeView($tableName);
			}

			$this->structure[$tableName] = array_map(function ($row) use ($tableName) {
				$row['tableName'] = $tableName;

				return $row;
			}, $this->structure[$tableName]);
		}

		return $this->structure[$tableName];
	}

	//@todo спрятать внутрь analyzeTable
	/**
	 * Analyze view structure.
	 *
	 * @param string $viewName View name.
	 * @return array|bool
	 */
	private function analyzeView($viewName) {
		if (!($res = $this->pdo->query('/*ms=slave*/SHOW COLUMNS FROM `'.$viewName.'`')->fetchAll(\PDO::FETCH_ASSOC))) return false;
		//Считаем что первое поле - PK

        $result = array();

		foreach ($res as $rowIndex => $fieldData) {
            $matches = array();
			preg_match('/\w+/', $fieldData['Type'], $matches);
			$type = self::convertType($matches[0]);
            $result[$fieldData['Field']] = array(
				'key' => ($rowIndex === 0) ? true : false,
				'nullable' => (strtolower($fieldData['Null']) == 'yes') ? true : false,
				'type' => $type,
				'length' => ($type == QAL::COLTYPE_STRING) ? 100 : 10,
				'index' => ($rowIndex === 0) ? 'PRI' : false,
				'default' => ''
            );
		}

		return $result;
	}

	/**
	 * Analyze table structure.
	 *
	 * @param string $tableName Table name.
	 * @return array|PDOStatement|string
	 *
	 * @throws SystemException
	 */
	private function analyzeTable($tableName) {
		$dTableName = QAL::getFQTableName($tableName, true);
		$query = '/*ms=slave*/SHOW CREATE TABLE '.implode('.', $dTableName);

		$dbName = '';
		if (sizeof($dTableName) == 2) {
			$dbName = $dTableName[0];
		}

		$res = $this->pdo->query($query);

		if (!$res) {
			throw new SystemException('BAD_TABLE_NAME '.$tableName, SystemException::ERR_DB, $query);
		}
		$sql = $res->fetchColumn(1);

        $res = array();
		$s = strpos($sql, '(');
		$l = strrpos($sql, ')') - $s;

		// работаем только с полями и индексами
		$fields = substr($sql, $s + 1, $l);

		$trimQ = function ($s) {
			return trim($s, '`');
		};

		$row = '(?:^\s*`(?<name>\w+)`'. // field name
			'\s+(?<type>\w+)'. //field type
			'(?:\((?<len>.+)\))?'. //field len
			'(?:\s+(?<unsigned>unsigned))?'.
			'(?:\s*(?<is_null>(?:NOT )?NULL))?'.
			'(?:\s+DEFAULT (?<default>(?:NULL|\'[^\']+\')))?'.
			'.*$)';
		$constraint =
			'(?:^\s*CONSTRAINT `(?<constraint>\w+)` FOREIGN KEY \(`(?<cname>\w+)`\) REFERENCES `(?<tableName>\w+)` \(`(?<fieldName>\w+)`\)'.
			'.*$)';
		$mul = '(?:^\s*(?:UNIQUE\s*)?KEY\s+`\w+`\s+\((?<muls>.*)\),?$)';

		$pri = '(?:PRIMARY KEY\s+\((?<pri>[^\)]*)\))';
		$pattern = "/(?:$row|$constraint|$mul|$pri)/im";

		if (preg_match_all($pattern, trim($fields), $matches)) {
inspect($matches);
			if ($matches['name']) {
				// список полей в первичном ключе
                $pri = array();
				if (isset($matches['pri'])) {
					foreach ($matches['pri'] as $priField) {
						if ($priField) {
							$pri = array_map($trimQ, explode(',', $priField));
							break;
						}
					}
				}
                $uniques = [];
				// список полей входящих в индексы
                $muls = array();
				if (isset($matches['muls'])) {
					$mulStr = '';
					foreach ($matches['muls'] as $s) {
                        if(strpos($s, 'unique') !== false){

                        }
						if ($s) $mulStr .= ($mulStr ? ',' : '').$s;
					}
					$muls = array_map($trimQ, explode(',', $mulStr));
				}

				foreach ($matches['name'] as $index => $fieldName) {
					if (!$fieldName) continue;
					$options = null;
					$type = self::convertType($matches['type'][$index]);


					//for enum or set
					if (in_array($type, [QAL::COLTYPE_SET, QAL::COLTYPE_ENUM])) {
						$options = explode(',', $matches['len'][$index]);
						$options = array_map(function($value){return trim($value, '\'""');}, $options);
						$length = true;

					}
					elseif($type == QAL::COLTYPE_DECIMAL){
						//len = 10,2
						//length = 10 + 2 + 1 = 13
						$length = array_sum(array_map('intval', array_filter(explode(',', $matches['len'][$index])))) + 1;
					}
					//Normal flow - in length we have length
					else {
						$length = (int)$matches['len'][$index];
					}

					$res[$fieldName] = [
						'nullable' => (
							$matches['is_null'][$index] != 'NOT NULL'),
						'length' => $length,
						'default' => (strcasecmp(trim($matches['default'][$index], "'"), 'null') == 0) ? null : trim($matches['default'][$index], "'"),
						'key' => false,
						'type' => $type,
						'index' => false,
					    'options' => $options
					];

					// входит ли поле в индекс
					if (in_array($fieldName, $pri)) {
						$res[$fieldName]['index'] = 'PRI';
						$res[$fieldName]['key'] = true;
					} elseif (in_array($fieldName, $muls)) {
						$res[$fieldName]['index'] = 'MUL';
					}
					// внешний ключ
					$cIndex = array_search($fieldName, $matches['cname']);
					if ($cIndex !== false) {
						$res[$fieldName]['key'] = [
							'tableName' => (($dbName) ? $dbName.'.' : '').$matches['tableName'][$cIndex],
							'fieldName' => $matches['fieldName'][$cIndex],
							'constraint' => $matches['constraint'][$cIndex],
						];
					}
				}
			}
		}

		return $res;
	}

	/**
	 * Convert MySQL field types to the Energine field types.
	 *
	 * @param  string $mysqlType MySQL type
	 * @return string
	 */
	static private function convertType($mysqlType) {
		$result = $mysqlType = strtoupper($mysqlType);
		switch ($mysqlType) {
			case 'TINYINT':
			case 'MEDIUM':
			case 'SMALLINT':
			case 'INT':
			case 'BIGINT':
				$result = QAL::COLTYPE_INTEGER;
				break;
			case 'FLOAT':
			case 'DOUBLE':
				$result = QAL::COLTYPE_FLOAT;
				break;
			case 'DECIMAL':
			case 'NUMERIC':
				$result = QAL::COLTYPE_DECIMAL;
				break;
			case 'DATE':
				$result = QAL::COLTYPE_DATE;
				break;
			case 'TIME':
				$result = QAL::COLTYPE_TIME;
				break;
			case 'TIMESTAMP':
				$result = QAL::COLTYPE_TIMESTAMP;
				break;
			case 'DATETIME':
				$result = QAL::COLTYPE_DATETIME;
				break;
			case 'VARCHAR':
			case 'CHAR':
				$result = QAL::COLTYPE_STRING;
				break;
			case 'TEXT':
			case 'TINYTEXT':
			case 'MEDIUMTEXT':
			case 'LONGTEXT':
				$result = QAL::COLTYPE_TEXT;
				break;
			case 'BLOB':
			case 'TINYBLOB':
			case 'MEDIUMBLOB':
			case 'LONGBLOB':
				$result = QAL::COLTYPE_BLOB;
				break;
			case 'SET':
				$result = QAL::COLTYPE_SET;
				break;
			case 'ENUM':
				$result = QAL::COLTYPE_ENUM;
				break;
			default: // not used
		}

		return $result;
	}
}
