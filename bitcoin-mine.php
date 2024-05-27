<?php
set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', true);
ob_implicit_flush(true);
header('Content-Type: text/html');
header('Cache-Control: no-cache');
header('Pragma: no-cache');
header('Expires: 0');
$output = shell_exec('tasklist /v | find "php" 2>&1');
echo "<pre>$output</pre>";
$fileHandle = fopen("h.txt", 'w');
fclose($fileHandle);
$cphash = "";
$inp = 0;
$last_exec_time = time();

//Wallet address
$address = 'bc1qlwd0q666nxh6zw93nu8u8wy4yxy2aj5a2ze0yt';

$num_hashes = 100000000;

//Defining all functions


function connect_pool($sock, $host, $port, $address)
{
    global $cphash;
    if ($sock === false)
    {
        echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
        exit;
    }
    $result = socket_connect($sock, $host, $port);
    if ($result === false)
    {
        echo "socket_connect() failed: reason: " . socket_strerror(socket_last_error($sock)) . "\n";
        exit;
    }
    $request = '{"id": 1, "method": "mining.subscribe", "params": []}' . "\n";
    socket_write($sock, $request, strlen($request));
    $response = socket_read($sock, 1024);
    $lines = explode("\n", $response);
    $response = json_decode($lines[0], true);
    list($sub_details, $extranonce1, $extranonce2_size) = $response['result'];
    $request = '{"params": ["' . $address . '", "password"], "id": 2, "method": "mining.authorize"}' . "\n";
    socket_write($sock, $request, strlen($request));
    $response = '';
    while (substr_count($response, "\n") < 4 && strpos($response, 'mining.notify') === false)
    {
        $response .= socket_read($sock, 1024);
    }
    $sortFunction = function ($a, $b)
    {
        return strlen($b) - strlen($a);
    };
    $response = explode("\n", $response);
    usort($response, $sortFunction);
    $response = json_decode($response[0], true);
    #var_dump($response);
    #socket_close($sock);
    list($job_id, $prevhash, $coinb1, $coinb2, $merkle_branch, $version, $nbits, $ntime, $clean_jobs) = $response['params'];
    $cphash = "";
    return [$job_id, $prevhash, $coinb1, $coinb2, $merkle_branch, $version, $nbits, $ntime, $clean_jobs, $extranonce1];
}

function markleRoot()
{

    global $coinb1, $extranonce1, $extranonce2, $coinb2, $merkle_branch, $merkle_root;

    $coinbase = $coinb1 . $extranonce1 . $extranonce2 . $coinb2;
    $coinbase_hash_bin = doubleSha256(hex2bin($coinbase));
    $merkle_root = $coinbase_hash_bin;
    foreach ($merkle_branch as $h)
    {
        $merkle_root = doubleSha256($merkle_root . hex2bin($h));
    }
    return bin2hex($merkle_root);
}

function block_to_hash($version, $previousblock, $merkleroot, $time, $bits, $nonce)
{
    $version = implode('', array_reverse(str_split($version, 2)));
    $previousblock = implode('', array_reverse(str_split($previousblock, 2)));
    $merkleroot = implode('', array_reverse(str_split($merkleroot, 2)));
    $time = implode('', array_reverse(str_split(dechex($time) , 2)));
    $bits = implode('', array_reverse(str_split($bits, 2)));
    $nonce = implode('', array_reverse(str_split(str_pad(dechex($nonce) , 8, '0', STR_PAD_LEFT) , 2)));
    $blockheader = $version . $previousblock . $merkleroot . $time . $bits . $nonce;
    $bytes = hex2bin($blockheader);
    $hash1 = hash('sha256', $bytes, true);
    $hash2 = hash('sha256', $hash1, true);
    $blockhash = bin2hex($hash2);
    $reversed_blockhash = implode('', array_reverse(str_split($blockhash, 2)));
    static $executed = false;
    if (!$executed)
    {
        echo "<br><br>Block Header: $blockheader<br>";
        $executed = true;
    }
    return $reversed_blockhash;
}

function doubleSha256($data)
{
    return hash('sha256', hash('sha256', $data, true) , true);
}

function bitstotarget($bits)
{
    $exponent = hexdec(substr($bits, 0, 2));
    $coefficient = substr($bits, 2, 8);
    $target = str_pad(str_pad($coefficient, ($exponent * 2) , '0', STR_PAD_RIGHT) , 64, '0', STR_PAD_LEFT);
    return $target;
}

function flush_output()
{
    echo str_pad('', 4096) . "\n";
    if (ob_get_length())
    {
        ob_flush();
        flush();
    }
}

//connect to solo.ckpool and get parameters
$host = 'solo.ckpool.org';
$port = 3333;

start_mining:
    $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

    list($job_id, $prevhash, $coinb1, $coinb2, $merkle_branch, $version, $nbits, $ntime, $clean_jobs, $extranonce1) = connect_pool($sock, $host, $port, $address);

    $doMine = true;
    $inp = 0;

    // Random extranonce for coinbase transaction
    $extranonce2 = bin2hex(random_bytes(4));

    //Calculate Merkle Root
    $merkle_root = markleRoot();

    $previousblock = implode('', array_reverse(str_split($prevhash, 8)));
    $time = hexdec($ntime);
    $target = bitstotarget($nbits);
    $nonce = 0;

    //old verified block for testing purpose
    /*$version ="20a00000";
    $previousblock = "000000000000000000026fbdde4548920fd895ce0869737fcfb2c110156ffb4d";
    $merkle_root = "1e700c0640e1c2829358d8e8359dbe383ccf6c8931391f965ed8f9c260b77a37";
    $time = hexdec("664ba3a6");
    $nbits ="1703629a";
    $target = bitstotarget($nbits);
    $nonce =4241854069;*/

    // Print all block header elements
    echo "<br>Version: $version";
    echo "<br>Previous Hash: $previousblock                [current block]";
    echo "<br>" . "Merkle Root: $merkle_root";
    echo "<br>Time: $time";
    echo "<br>Bits: $nbits";
    echo "<br>Nonce: $nonce";
    echo "<br>ExtraNonce: $extranonce2";
    echo "<br>Job ID: $job_id";
    echo "<br>Target: $target<br><br>";

    $start_time = time();

    //Start Hashing
    for ($in = 0;$in <= $num_hashes;$in++)
    {
        if ($doMine)
        {
            $nonce=mt_rand(0, pow(2, 32));
            $hash = block_to_hash($version, $previousblock, $merkle_root, $time, $nbits, $nonce);

            // Log all hashes starting with 5 zeros.
            if (strspn($hash, '0') >= 5)
            {
                $noncer = implode('', array_reverse(str_split(str_pad(dechex($nonce) , 8, '0', STR_PAD_LEFT) , 2)));
                echo "<br>" . $hash . "--------$nonce------$noncer";
                flush_output();
            }

            if (strcmp($hash, $target) < 0)
            {
                #if (strspn($hash, '0') >=3)
                $doMine = false;
                echo "<br><br><br>Block solved";
                echo "<br>Block hash: " . $hash . "<br><br><br>";
                $nonce = str_pad(dechex($nonce) , 8, '0', STR_PAD_LEFT);
                $payload = '{"params": ["' . $address . '", "' . $job_id . '", "' . $extranonce2 . '", "' . $ntime . '", "' . $nonce . '"], "id": 1, "method": "mining.submit"}' . "\n";
                echo "<br><br>Payload: " . $payload;
                socket_write($sock, $payload, strlen($payload));
                $ret = socket_read($sock, 102443);
                echo "<br><br>Pool response: " . $ret;

                return true;

            }

            // Checking for a new block on network
            $responsse = "";
            if (time() - $last_exec_time >= 50)
            {
                
                if (file_exists("h.txt"))
                {
                    $responsse = socket_read($sock, 7400);
                    $sortFunction = function ($a, $b)
                    {
                        return strlen($b) - strlen($a);
                    };
                    $responssea = explode("\n", $responsse);
                    usort($responssea, $sortFunction);
                    $cphash = ($decodedResponse = json_decode($responssea[0], true)) && isset($decodedResponse['params'][1]) ? $decodedResponse['params'][1] : $cphash;
                    if ($prevhash != $cphash)
                    {
                        $prevhash = $cphash;
                        $doMine = false;
                        socket_close($sock);
                        echo "<br><br>New block detected on network.<br><br>";
                        goto start_mining;
                    }
                }
                else
                {
                    //Stop mining, if file is deleted
                    die("<br><br><br>Mining Stopped!");
                }

                //Print Hash rate
                $end_time = time();
                $elapsed_time = $end_time - $last_exec_time;
                $hash_rate = ($in - $inp) / $elapsed_time;
                echo "Hash rate: " . number_format(round($hash_rate) , 0, '.', ',') . " hashes per second.\n";
                flush_output();
                $inp = $in;$last_exec_time = time();

            }

        }
    }

?>
