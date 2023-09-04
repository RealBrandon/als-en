<?php
if (!function_exists('env')) {
    function env($key, $default = false)
    {
        $result = getenv($key);
        if ($result !== false) {
            if ($result === "true") return true;
            if ($result === "false") return false;
            return $result;
        }
        return $default;
    }
}

if (!function_exists('applog')) {
    function applog($content = '')
    {
        echo '[' . date("Y-m-d H:i:s") . "] {$content}\n";
    }
}

if (!function_exists('debuglog')) {
    function debuglog($content = '')
    {
        if (env('DEBUG', false) === false) return;
        echo '[' . date("Y-m-d H:i:s") . "] DEBUG - {$content}\n";
    }
    debuglog("DEBUG Mode enabled");
}

if (!function_exists('execute')) {
    function execute($command)
    {
        ob_start();
        passthru($command, $exitCode);
        $result = ob_get_contents();
        ob_end_clean();

        return [$result, $exitCode];
    }
}

if (!function_exists('maxmind_update')) {
    function maxmind_update($key)
    {
        $fp = fopen('/tmp/maxmind.tar.gz', 'w');

        applog("INFO: Downloading IP Database...");

        [$errCode, $_, $httpCode] =  _wget(
            "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key={$key}&suffix=tar.gz",
            [
                CURLOPT_RETURNTRANSFER => 0,
                CURLOPT_FILE => $fp,
            ]
        );
        fclose($fp);
        if ($httpCode === 200) {
            applog("INFO: Downloaded IP Database, Verifing...");
            execute('/bin/tar zxvf /tmp/maxmind.tar.gz -C /tmp');
            $files = glob('/tmp/*/*.mmdb');
            $dbFile = array_pop($files);
            unlink('/app/ip.mmdb');
            copy($dbFile, 'app/ip.mmdb');
            # clean up /tmp
            execute("rm -rf /tmp");
            return true;
        }
        return false;
    }
}

if (!function_exists('_wget')) {
    function _wget($url, $options = [])
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt_array($curl, $options);
        $data = curl_exec($curl);
        $errNo = curl_errno($curl);
        $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [$errNo, $data, $httpcode];
    }
}

if (!function_exists('geo_lookup_ip')) {
    function geo_lookup_ip($ip, $language = 'en-GB')
    {
        /** @var MaxMind\Db\Reader $reader */
        global $reader;
        $geoData = $reader->get($ip);

        $geo = [];
        if (isset($geoData['country'])) {
            $geo[] = $geoData['country']['names'][$language] ?? $geoData['country']['names']['en'];
        }
        if (isset($geoData['city'])) {
            $geo[] = $geoData['city']['names'][$language] ?? $geoData['city']['names']['en'];
        }

        $geo = implode(' - ', $geo);

        return $geo;
    }
}

class Process
{
    private $process = null;
    private $pipes = [];
    private $output = '';
    private $command = '';

    public function __construct($program, array $args = [])
    {
        $this->command = implode(' ', array_merge([$program], $args));
        debuglog("Run command:" . $this->command);
    }

    public function getOutput()
    {
        $content = $this->output;
        $this->output = '';
        return $content;
    }

    public function close()
    {
        proc_terminate($this->process);
    }

    public function run()
    {
        if (is_null($this->process)) {
            $this->process = proc_open(
                $this->command,
                [
                    ['pipe', 'r'],
                    ['pipe', 'w'],
                    ['pipe', 'w'],
                ],
                $this->pipes
            );
            stream_set_blocking($this->pipes[1], false);
            stream_set_blocking($this->pipes[2], false);
        }
        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $this->output .= fgets($this->pipes[1], 1024);
            $this->output .= fgets($this->pipes[2], 1024);
            if (strlen($this->output) != 0) return true;
            proc_close($this->process);
            return false;
        }

        $readPipes = [$this->pipes[1], $this->pipes[2]];
        $writePipes = [];
        $exceptPipes = [];
        $streamChanges = stream_select(
            $readPipes,
            $writePipes,
            $exceptPipes,
            1,
            null
        );
        if ($streamChanges === false) return false;
        $this->output .= fgets($this->pipes[1], 1024);
        $this->output .= fgets($this->pipes[2], 1024);

        return true;
    }
}
