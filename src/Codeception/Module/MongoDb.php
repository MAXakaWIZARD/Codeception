<?php
namespace Codeception\Module;

use Codeception\Module as CodeceptionModule;
use Codeception\Configuration as Configuration;
use Codeception\Exception\ModuleConfigException;
use Codeception\Exception\ModuleException;
use Codeception\Lib\Driver\MongoDb as MongoDbDriver;
use Codeception\TestInterface;

/**
 * Works with MongoDb database.
 *
 * The most important function of this module is cleaning database before each test.
 * To have your database properly cleaned you should configure it to access the database.
 *
 * In order to have your database populated with data you need a valid js file with data
 * (of the same style which can be fed up to mongo binary)
 * File can be generated by RockMongo export command
 * Just put it in ``` tests/_data ``` dir (by default) and specify path to it in config.
 * Next time after database is cleared all your data will be restored from dump.
 * The DB preparation should as following:
 * - clean database
 * - system collection system.users should contain the user which will be authenticated while script performs DB operations
 *
 * Connection is done by MongoDb driver, which is stored in Codeception\Lib\Driver namespace.
 * Check out the driver if you get problems loading dumps and cleaning databases.
 *
 * ## Status
 *
 * * Maintainer: **judgedim**, **davert**
 * * Stability: **beta**
 * * Contact: codecept@davert.mail.ua
 *
 * *Please review the code of non-stable modules and provide patches if you have issues.*
 *
 * ## Config
 *
 * * dsn *required* - MongoDb DSN with the db name specified at the end of the host after slash
 * * user *required* - user to access database
 * * password *required* - password
 * * dump - path to database dump
 * * populate: true - should the dump be loaded before test suite is started.
 * * cleanup: true - should the dump be reloaded after each test
 *
 */
class MongoDb extends CodeceptionModule
{
    /**
     * @api
     * @var
     */
    public $dbh;

    /**
     * @var
     */

    protected $dumpFile;
    protected $isDumpFileEmpty = true;

    protected $config = [
        'populate' => true,
        'cleanup'  => true,
        'dump'     => null,
        'user'     => null,
        'password' => null
    ];

    protected $populated = false;

    /**
     * @var \Codeception\Lib\Driver\MongoDb
     */
    public $driver;

    protected $requiredFields = ['dsn'];

    public function _initialize()
    {
        if ($this->config['dump'] && ($this->config['cleanup'] or ($this->config['populate']))) {

            if (!file_exists(Configuration::projectDir() . $this->config['dump'])) {
                throw new ModuleConfigException(
                    __CLASS__,
                    "File with dump doesn't exist.\n
                    Please, check path for dump file: " . $this->config['dump']
                );
            }
            $this->dumpFile = Configuration::projectDir() . $this->config['dump'];
            $this->isDumpFileEmpty = false;

            $content = file_get_contents($this->dumpFile);
            $content = trim(preg_replace('%/\*(?:(?!\*/).)*\*/%s', "", $content));
            if (!sizeof(explode("\n", $content))) {
                $this->isDumpFileEmpty = true;
            }
        }

        try {
            $this->driver = MongoDbDriver::create(
                $this->config['dsn'],
                $this->config['user'],
                $this->config['password']
            );
        } catch (\MongoConnectionException $e) {
            throw new ModuleException(__CLASS__, $e->getMessage() . ' while creating Mongo connection');
        }

        // starting with loading dump
        if ($this->config['populate']) {
            $this->cleanup();
            $this->loadDump();
            $this->populated = true;
        }
    }

    public function _before(TestInterface $test)
    {
        if ($this->config['cleanup'] && !$this->populated) {
            $this->cleanup();
            $this->loadDump();
        }
    }

    public function _after(TestInterface $test)
    {
        $this->populated = false;
    }

    protected function cleanup()
    {
        $dbh = $this->driver->getDbh();
        if (!$dbh) {
            throw new ModuleConfigException(
                __CLASS__,
                "No connection to database. Remove this module from config if you don't need database repopulation"
            );
        }
        try {
            $this->driver->cleanup();

        } catch (\Exception $e) {
            throw new ModuleException(__CLASS__, $e->getMessage());
        }
    }

    protected function loadDump()
    {
        if ($this->isDumpFileEmpty) {
            return;
        }
        try {
            $this->driver->load($this->dumpFile);
        } catch (\Exception $e) {
            throw new ModuleException(__CLASS__, $e->getMessage());
        }
    }

    /**
     * Specify the database to use
     *
     * ``` php
     * <?php
     * $I->useDatabase('db_1');
     * ```
     *
     * @param $dbName
     */
    public function useDatabase($dbName)
    {
        $this->driver->setDatabase($dbName);
    }

    /**
     * Inserts data into collection
     *
     * ``` php
     * <?php
     * $I->haveInCollection('users', array('name' => 'John', 'email' => 'john@coltrane.com'));
     * $user_id = $I->haveInCollection('users', array('email' => 'john@coltrane.com'));
     * ```
     *
     * @param $collection
     * @param array $data
     */
    public function haveInCollection($collection, array $data)
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);
        if ($this->driver->isLegacy()) {
            $collection->insert($data);
            return $data['_id'];
        } else {
            $response = $collection->insertOne($data);
            return $response->getInsertedId()->__toString();
        }
    }

    /**
     * Checks if collection contains an item.
     *
     * ``` php
     * <?php
     * $I->seeInCollection('users', array('name' => 'miles'));
     * ```
     *
     * @param $collection
     * @param array $criteria
     */
    public function seeInCollection($collection, $criteria = [])
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);
        $res = $collection->count($criteria);
        \PHPUnit_Framework_Assert::assertGreaterThan(0, $res);
    }

    /**
     * Checks if collection doesn't contain an item.
     *
     * ``` php
     * <?php
     * $I->dontSeeInCollection('users', array('name' => 'miles'));
     * ```
     *
     * @param $collection
     * @param array $criteria
     */
    public function dontSeeInCollection($collection, $criteria = [])
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);
        $res = $collection->count($criteria);
        \PHPUnit_Framework_Assert::assertLessThan(1, $res);
    }

    /**
     * Grabs a data from collection
     *
     * ``` php
     * <?php
     * $cursor = $I->grabFromCollection('users', array('name' => 'miles'));
     * ```
     *
     * @param $collection
     * @param array $criteria
     * @return \MongoCursor
     */
    public function grabFromCollection($collection, $criteria = [])
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);
        return $collection->findOne($criteria);
    }

    /**
     * Grabs the documents count from a collection
     *
     * ``` php
     * <?php
     * $count = $I->grabCollectionCount('users');
     * // or
     * $count = $I->grabCollectionCount('users', array('isAdmin' => true));
     * ```
     *
     * @param $collection
     * @param array $criteria
     * @return integer
     */
    public function grabCollectionCount($collection, $criteria = [])
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);
        return $collection->count($criteria);
    }

    /**
     * Asserts that an element in a collection exists and is an Array
     *
     * ``` php
     * <?php
     * $I->seeElementIsArray('users', array('name' => 'John Doe') , 'data.skills');
     * ```
     *
     * @param String $collection
     * @param Array $criteria
     * @param String $elementToCheck
     */
    public function seeElementIsArray($collection, $criteria = [], $elementToCheck = null)
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);

        $res = $collection->count(
            array_merge(
                $criteria,
                [
                    $elementToCheck => ['$exists' => true],
                    '$where' => "Array.isArray(this.{$elementToCheck})"
                ]
            )
        );
        if ($res > 1) {
            throw new \PHPUnit_Framework_ExpectationFailedException(
                'Error: you should test against a single element criteria when asserting that elementIsArray'
            );
        }
        \PHPUnit_Framework_Assert::assertEquals(1, $res, 'Specified element is not a Mongo Object');
    }

    /**
     * Asserts that an element in a collection exists and is an Object
     *
     * ``` php
     * <?php
     * $I->seeElementIsObject('users', array('name' => 'John Doe') , 'data');
     * ```
     *
     * @param String $collection
     * @param Array $criteria
     * @param String $elementToCheck
     */
    public function seeElementIsObject($collection, $criteria = [], $elementToCheck = null)
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);

        $res = $collection->count(
            array_merge(
                $criteria,
                [
                    $elementToCheck => ['$exists' => true],
                    '$where' => "! Array.isArray(this.{$elementToCheck}) && isObject(this.{$elementToCheck})"
                ]
            )
        );
        if ($res > 1) {
            throw new \PHPUnit_Framework_ExpectationFailedException(
                'Error: you should test against a single element criteria when asserting that elementIsObject'
            );
        }
        \PHPUnit_Framework_Assert::assertEquals(1, $res, 'Specified element is not a Mongo Object');
    }

    /**
     * Count number of records in a collection
     *
     * ``` php
     * <?php
     * $I->seeNumElementsInCollection('users', 2);
     * $I->seeNumElementsInCollection('users', 1, array('name' => 'miles'));
     * ```
     *
     * @param $collection
     * @param integer $expected
     * @param array $criteria
     */
    public function seeNumElementsInCollection($collection, $expected, $criteria = [])
    {
        $collection = $this->driver->getDbh()->selectCollection($collection);
        $res = $collection->count($criteria);
        \PHPUnit_Framework_Assert::assertSame($expected, $res);
    }
}
