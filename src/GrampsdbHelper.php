<?php

namespace Treii28\Grampsdb;

use Illuminate\Support\Facades\DB;

class GrampsdbHelper
{
    /**
     * @var string $dbConn
     */
    protected static $dbConn = 'grampsdb';

    /**
     * characters special to a url that can be used in urlEncode
     * @var string[] $url_special
     * @see urlEncode()
     */
    protected static $url_special = [
        "/" => '%2F',
        "=" => '%3D',
        "?" => '%3F',
        "%" => '%25',
        "&" => '%26',
    ];

    /**
     * list of characters and replacements which can be used with urlEncode()
     *
     * @var string[] $url_entities
     * @see urlEncode()
     */
    protected static $url_entities = [
        ' ' => '%20',
        '!' => '%21',
        '"' => '%22',
        "#" => '%23',
        "$" => '%24',
        "'" => '%27',
        "(" => '%28',
        ")" => '%29',
        '*' => '%2A',
        "+" => '%2B',
        "," => '%2C',
        ":" => '%3A',
        ";" => '%3B',
        "@" => '%40',
        "[" => '%5B',
        "]" => '%5D'
    ];

    /**
     * @param null|string $path
     * @return string|null
     * @throws \Exception if unable to find a valid python executable
     */
    private static function getPythonExecutable($path=null)
    {
        $pyExe = null;

        if(is_file($path) && is_executable($path)) { // use specified if available
            $pyExe = $path;
        } else if(function_exists('env')) { // use env if available
            $pyExe = env('PYTHON_EXE');
        } else if(is_file('/usr/bin/which') && is_executable('/usr/bin/which')) {
            // see if it is in the unix path
            $pyExe = exec('/usr/bin/which python');
        }

        // did we find something useable?
        if(!is_file($pyExe) && is_executable($pyExe))
            throw new \Exception("python executable not found!");

        return $pyExe;
    }

    /**
     * @param string $connName
     */
    public static function setDbConnection($connName='grampsdb') { static::$dbConn = $connName; }

    /**
     * @param string|null $connName
     * @return \Illuminate\Database\ConnectionInterface
     */
    public static function getDbHandle($connName=null) {
        if(empty($connName)) $connName = self::$dbConn;
        return DB::connection($connName);
    }

    /**
     * call either a pyinstaller binary or python script with raw blob data to be unpickled
     *
     * @param string $b  binary data of blob
     * @return false|mixed
     */
    public static function unpickle($b)
    {
        $cmd = realpath(__DIR__."/../bin/unpickle");
        if(!(is_file($cmd) && is_executable($cmd))) { // make sure unpickle cmd exists
            if (is_file($cmd.".py")) {
                $cmd = sprintf("%s %s.py", self::getPythonExecutable(), $cmd);
            } else
                return static::unpyckle($b); // try direct python call
        }

        $descriptorspec = [
            ["pipe", "r"],  // stdin is a pipe that the child will read from
            ["pipe", "w"],  // stdout is a pipe that the child will write to
            ["pipe", "w"]   // stderr is a file to write to
        ];

        $cwd = dirname($cmd);
        $env = [];

        $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, $env);

        if (is_resource($process)) {
            // $pipes now looks like this:
            // 0 => writeable handle connected to child stdin
            // 1 => readable handle connected to child stdout
            // 2 => readable handle connected to child stderr

            fwrite($pipes[0], $b);
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // It is important that you close any pipes before calling
            // proc_close in order to avoid a deadlock
            $return_value = proc_close($process);

            if(self::isJson($output))
                return json_decode($output);
            else
                return false;
        }
        return false;
    }
    /**
     * use a python exec call to 'unpickle' the blob_data
     *   to get the binary blob into a command line argument, base64 encode it
     *   to get the data back out of python, json serialize it
     * @param string $blob binary blob data
     * @return mixed
     */
    public static function unpyckle($blob) {
        $bblob = base64_encode($blob);
        $cmd = sprintf("import pickle; import base64; import json; print(json.dumps(pickle.loads(base64.b64decode('%s'))))", $bblob);
        //file_put_contents(base_path("tests")."/blob.out", $blob);
        $pcmd = sprintf("%s -c \"%s\"", self::getPythonExecutable(), $cmd);
        $result = exec($pcmd);
        $resdec = json_decode($result);
        return $resdec;
    }

    /**
     * Use it for json_encode some corrupt UTF-8 chars
     * useful for = malformed utf-8 characters possibly incorrectly encoded by json_encode
     * @param $mixed
     * @return array|false|mixed|string|string[]|null
     */
    public static function utf8ize( $mixed ) {
        if(is_object($mixed))
            $mixed = (array)$mixed;
        if (is_array($mixed)) {
            // make sure any blob data has already been unpickled to an array
            if(array_key_exists('blob_data', $mixed) && !is_array($mixed['blob_data']))
                $mixed['blob_data'] = self::unpyckle($mixed['blob_data']);
            foreach ($mixed as $key => $value) {
                $mixed[$key] = self::utf8ize($value);
            }
        } elseif (is_string($mixed)) {
            return mb_convert_encoding($mixed, "UTF-8", "UTF-8");
        }
        return $mixed;
    }

    private static function mapPersonData($data) {
        if(count($data) != 21) return false;
        $genders = ['Female','Male'];
        $genderId = $data[2];
        return [
            'handle' => $data[0],
            'gramps_id' => $data[1],
            'gender' => $genders[$genderId],
            'primary_name' => $data[3],
            'alternate_names' => $data[4],
            'death_ref_index' => $data[5],
            'birth_ref_index' => $data[6],
            'event_ref_index' => $data[7],
            'family_list' => $data[8],
            'parent_family_list' => $data[9],
            'media_list' => $data[10],
            'address_list' => $data[11],
            'attribute_list' => $data[12],
            'urls' => $data[13],
            'lds_ord_list' => $data[14],
            'citation_list' => $data[15],
            'note_list' => $data[16],
            'change' => $data[17],
            'tag_list' => $data[18],
            'private' => $data[19],
            'person_ref_list' => $data[20]
        ];
    }
    public static function getPersons()
    {
        $grampsPersons = [];
        $gPersons = self::getDbHandle()->table('person')->get();
        foreach($gPersons as $pRec) {
            $gid = $pRec->gramps_id;
            // decode blob_data if any
            if(property_exists($pRec, 'blob_data')) {
                $blob_data = static::unpyckle($pRec->blob_data);
                $pRec->person_data = static::mapPersonData($blob_data);
                unset($pRec->blob_data);
            }

            $grampsPersons[$gid] = $pRec;
        }
        return $grampsPersons;
    }

    public static function getPersonByHandle($ghan,$withMedia=false)
    {
        $person = self::getDbHandle()->table('person')->where('handle', $ghan)->first();
        if($withMedia)
            $person->media = self::getMediaByPersonHandle($person->handle);

        // decode blob_data if any
        if(property_exists($person, 'blob_data')) {
            $blob_data = static::unpyckle($person->blob_data);
            $person->person_data = static::mapPersonData($blob_data);
            unset($person->blob_data);
        }

        return $person;
    }

    public static function getPersonById($gid, $withMedia=false)
    {
        $person = self::getDbHandle()->table('person')->where('gramps_id', $gid)->first();
        // decode blob_data if any
        if(property_exists($person, 'blob_data'))
            $person->blob_data = static::unpyckle($person->blob_data);

        if($withMedia)
            $person->media = self::getMediaByPersonHandle($person->handle);
        return $person;
    }

    public static function getPersonIdByHandle($ghan)
    {
        $gPerson = self::getPersonByHandle($ghan);
        if(is_object($gPerson) && property_exists($gPerson, 'gramps_id'))
            return $gPerson->gramps_id;
        else return false;
    }

    public static function getPersonHandleById($gid) {
        $gPerson = self::getPersonById($gid);
        if(is_object($gPerson) && property_exists($gPerson, 'handle'))
            return $gPerson->handle;
        else return false;
    }

    public static function getMediaByPersonId($gid) {
        if($pid = self::getPersonHandleById($gid))
            return self::getMediaByPersonHandle($pid);
        else return false;
    }

    public static function getRefByPersonid($pid, $oc=null, $rc=null)
    {
        if($phan = self::getPersonHandleById($pid))
            return self::getRefByPersonHandle($phan, $oc, $rc);
    }

    /**
     * @param $ghan  object handle
     * @param string $oc  object class (e.g. 'Person')
     * @param string $rc  ref class (e.g. 'Media')
     * @return \Illuminate\Support\Collection
     */
    public static function getRefByPersonHandle($ghan, $oc=null, $rc=null)
    {
        $whereData = [ 'obj_handle' => $ghan ];
        if(!empty($oc)) $whereData['obj_class'] = $oc;
        if(!empty($rc)) $whereData['ref_class'] = $rc;

        $reference = self::getDbHandle()->table('reference')->where($whereData)->get();

        return $reference;
    }

    // https://www.gramps-project.org/docs/gen/gen_lib.html?highlight=eventtype#module-gramps.gen.lib.eventtype
    private static $eventTypes = [
        '-1' => 'UNKNOWN',
        '0' => 'CUSTOM',
        '1' => 'MARRIAGE',
        '2' => 'MARR_SETTL',
        '3' => 'MARR_LIC',
        '4' => 'MARR_CONTR',
        '5' => 'MARR_BANNS',
        '6' => 'ENGAGEMENT',
        '7' => 'DIVORCE',
        '8' => 'DIV_FILING',
        '9' => 'ANNULMENT',
        '10' => 'MARR_ALT',
        '11' => 'ADOPT',
        '12' => 'BIRTH',
        '13' => 'DEATH',
        '14' => 'ADULT_CHRISTEN',
        '15' => 'BAPTISM',
        '16' => 'BAR_MITZVAH',
        '17' => 'BAS_MITZVAH',
        '18' => 'BLESS',
        '19' => 'BURIAL',
        '20' => 'CAUSE_DEATH',
        '21' => 'CENSUS',
        '22' => 'CHRISTEN',
        '23' => 'CONFIRMATION',
        '24' => 'CREMATION',
        '25' => 'DEGREE',
        '26' => 'EDUCATION',
        '27' => 'ELECTED',
        '28' => 'EMIGRATION',
        '29' => 'FIRST_COMMUN',
        '30' => 'IMMIGRATION',
        '31' => 'GRADUATION',
        '32' => 'MED_INFO',
        '33' => 'MILITARY_SERV',
        '34' => 'NATURALIZATION',
        '35' => 'NOB_TITLE',
        '36' => 'NUM_MARRIAGES',
        '37' => 'OCCUPATION',
        '38' => 'ORDINATION',
        '39' => 'PROBATE',
        '40' => 'PROPERTY',
        '41' => 'RELIGION',
        '42' => 'RESIDENCE',
        '43' => 'RETIREMENT',
        '44' => 'WILL'
    ];
    private static function mapEventData($data) {
        if(count($data) != 13) return false;
        $eventTypeId = $data[2][0];
        return [
            'handle' => $data[0],
            'gramps_id' => $data[1],
            'type' => [
                'type_id' => $data[2][0],
                'type_name' => (array_key_exists($eventTypeId, static::$eventTypes)?static::$eventTypes[$eventTypeId]:'')
            ],
            'date' => $data[3], // todo convert to php date?
            'description' => $data[4],
            'place' => $data[5],
            'citation_list' => $data[6],
            'note_list' => $data[7],
            'media_list' => $data[8],
            'attribute_list' => $data[9],
            'change' => $data[10],
            'tag_list' => $data[11],
            'private' => $data[12]
        ];
    }

    public static function getEventsByPersonHandle($ghan)
    {
        $events = [];
        $epRefs = self::getRefByPersonHandle($ghan, 'Person', 'Event');

        foreach($epRefs as $r) {
            $rh = $r->ref_handle;
            $eRec = self::getDbHandle()->table('event')->where(['handle' => $rh])->first();
            $eid = $eRec->gramps_id;
            // decode blob_data if any
            if(property_exists($eRec, 'blob_data')) {
                // try different methods to unpickle
                $blob_data = static::unpickle($eRec->blob_data);
                if($blob_data != false) {
                    $eRec->event_data = static::mapEventData($blob_data);
                    unset($eRec->blob_data);
                }
                else throw new \Exception("unable to parse blob_data!");
            }
            $events[$eid] = $eRec;
        }
        return $events;
    }

    private static function mapMediaData($data) {
        if(count($data) != 13) return false;
        return [
            'handle' => $data[0],
            'gramps_id' => $data[1],
            'path' => $data[2],
            'mime' => $data[3],
            'description' => $data[4],
            'checksum' => $data[5],
            'attribute_list' => $data[6],
            'citation_list' => $data[7],
            'note_list' => $data[8],
            'change' => $data[9],
            'date' => $data[10], // todo convert to php date
            'tag_list' => $data[11],
            'private' => $data[12]
        ];
    }
    public static function getMediaByPersonHandle($ghan, $skipPath=true) {
        $media = [];
        $mpRefs = self::getRefByPersonHandle($ghan,'Person','Media');
        //$mPath = ((function_exists('env')) ? env('GEDCOM_MEDIA', 'media') : 'media'); // used for local images
        foreach($mpRefs as $r) {
            $rh = $r->ref_handle;
            $mRec = self::getDbHandle()->table('media')->where(['handle' => $rh])->first();

            $mid = $mRec->gramps_id;
            // decode blob_data if any
            if(property_exists($mRec, 'blob_data')) {
                $blob_data = static::unpyckle($mRec->blob_data);
                $mRec->media_data = static::mapMediaData($blob_data);
                unset($mRec->blob_data);
            }

            // uncomment for just filename
            //$mRec->filename = preg_replace('|.*'.DIRECTORY_SEPARATOR.'([^'.DIRECTORY_SEPARATOR.']+)$|', "$1", $mRec->path);
            // insert relative url from site root for this server
            //$mRec->url = preg_replace('|.*'.DIRECTORY_SEPARATOR.'([^'.DIRECTORY_SEPARATOR.']+)$|', '/'.$mPath."/$1", $mRec->path);
            $media_path = (function_exists('env') ? env('GEDCOM_MEDIA_PATH', 'gedcomx/media') : 'gedcomx/media');
            $mRec->url = self::getUrlFromAwsBucket(basename($mRec->path),$media_path);
            if($skipPath) unset($mRec->path); // remove path if not relevant to local resources
            $media[$mid] = $mRec;
        }
        return $media;
    }
    /**
     * test an array to see if it appears to be an associative array
     *
     * @param mixed $arr
     * @return bool
     * @static
     */
    public static function isAssoc($arr)
    {
        if(!is_array($arr)) return false;
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * try to detect if a string is a json string
     *
     * @param $str
     * @return bool
     */
    public static function isJson($str) {
        if(is_string($str) && !empty($str)) {
            json_decode($str);
            return (json_last_error() == JSON_ERROR_NONE);
        }
        return false;
    }

    /**
     * custom url encode using specified associative array of replacements
     *   will default to self::$url_entities if no replacement list is given
     * @param string $string
     * @param array $incSpec
     * @return string
     */
    public static function urlEncode($string,$entities=null)
    {
        if(is_null($entities))
            $entities = self::$url_entities;

        if(!self::isAssoc($entities))
            throw new \Exception("invalid replacement list");

        return str_replace(array_keys($entities), array_values($entities), $string);
    }

    public static function getUrlFromAwsBucket($filename,$path=null,$bucket=null,$region=null) {
        if(empty($region) && function_exists('env'))
            $region=env('AWS_REGION', 'us-east-1');
        if(empty($bucket) && function_exists('env'))
            $bucket = env('AWS_BUCKET', 'grampsmedia');
        // replace any backslashes and get rid of any leading or trailing slashes or whitespace on path
        if(!empty($path)) {
            // swap backslashes and remove multiple separators
            $path = preg_replace( "|[\\\\/]+|", "/", $path );
            // remove any leading path separator
            $path = preg_replace( '|[/\s]*$|', '', $path );
            // remove any trailing path separator
            $path = preg_replace( '|^[/\s]*|', '', $path );
        }
        // if the (stripped) path is not empty, add a trailing slash to the path
        $fullpath = self::urlEncode((empty($path) ? '' : $path."/") . $filename );

        return sprintf('https://%s.s3.%s.amazonaws.com/%s', $bucket, $region, $fullpath );
    }
}