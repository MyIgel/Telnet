<?php
namespace Bestnetwork\Telnet;

/**
* TelnetClient class
*
* Used to execute remote commands via telnet connection
* Uses sockets functions and fgetc() to process result
*
* All methods throw Exceptions on error
*
* Written by Dalibor Andzakovic <dali@swerve.co.nz>
* Based on the code originally written by Marc Ennaji and extended by
* Matthias Blaser <mb@adfinis.ch>
*
* Extended by Christian Hammers <chammers@netcologne.de>
* and Igor Scheller <igor.scheller@igorshp.de>
*/
class TelnetClient {

    protected $host;
    protected $port;
    protected $timeout;

    protected $socket = NULL;
    protected $buffer = NULL;
    protected $globalBuffer = NULL;
    protected $prompt;
    protected $errPrompt;
    protected $errNo;
    protected $errStr;

    protected $NULL;
    protected $CR;
    protected $DC1;
    protected $WILL;
    protected $WONT;
    protected $DO;
    protected $DONT;
    protected $IAC;

    /**
     * @var bool Is binary mode enabled?
     */
    protected $binaryMode = false;

    /**
     * Constructor. Initialises host, port and timeout parameters
     * defaults to localhost port 23 (standard telnet port)
     *
     * @param string $host    Host name or IP address
     * @param int    $port    TCP port number
     * @param int    $timeout Connection timeout in seconds
     * @param string $prompt
     * @param string $errPrompt
     * @throws TelnetException
     */
    public function __construct( $host = '127.0.0.1', $port = 23, $timeout = 10, $prompt = '$', $errPrompt = 'ERROR' ){
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->prompt = $prompt;
        $this->errPrompt = $errPrompt;

        // set some telnet special characters
        $this->NULL = chr(0);
        $this->CR = chr(13);
        $this->DC1 = chr(17);
        $this->WILL = chr(251);
        $this->WONT = chr(252);
        $this->DO = chr(253);
        $this->DONT = chr(254);
        $this->IAC = chr(255);

        $this->connect();
    }

    /**
     * Destructor. Cleans up socket connection and command buffer
     *
     * @return void
     */
    public function __destruct(){
        // cleanup resources
        $this->disconnect();
        $this->buffer = NULL;
        $this->globalBuffer = NULL;
    }

    /**
     * Attempts connection to remote host. Returns TRUE if successful.
     *
     * @return bool
     * @throws TelnetException
     */
    public function connect(){
        // check if we need to convert host to IP
        if( !preg_match('/([0-9]{1,3}\\.){3,3}[0-9]{1,3}/', $this->host) ){
            $ip = gethostbyname($this->host);

            if( $this->host == $ip ){
                throw new TelnetException('Cannot resolve ' . $this->host);
            }else{
                $this->host = $ip;
            }
        }

        // attempt connection
        $this->socket = @fsockopen($this->host, $this->port, $this->errNo, $this->errStr, $this->timeout);

        if( !$this->socket ){
            throw new TelnetException('Cannot connect to ' . $this->host . ' on port ' . $this->port);
        }
    }

    /**
     * Closes IP socket
     *
     * @throws TelnetException
     */
    public function disconnect(){
        if( $this->socket ){
            if( !fclose($this->socket) ){
                throw new TelnetException('Error while closing telnet socket');
            }
            $this->socket = NULL;
        }
    }

    /**
     * Executes command and returns a string with result.
     * This method is a wrapper for lower level private methods
     *
     * @param string      $command Command to execute
     * @param null|string $prompt
     * @param null|string $errPrompt
     * @return string|bool Command result or true in binary mode
     * @throws TelnetException
     */
    public function execute( $command, $prompt = NULL, $errPrompt = NULL ){
        if($this->binaryMode){
            $this->executeBlind($command);
            return true;
        }

        $this->write($command);
        $this->read($prompt, $errPrompt);
        return $this->getBuffer();
    }

    /**
     * Executes the given command without output
     *
     * @param string $command
     * @param bool   $addNewLine
     */
    public function executeBlind($command, $addNewLine = true){
        $buffer = $this->buffer;

        $this->write($command, $addNewLine);

        $this->buffer = $buffer;
    }

    /**
     * Attempts login to remote host.
     * This method is a wrapper for lower level private methods and should be
     * modified to reflect telnet implementation details like login/password
     * and line prompts. Defaults to standard unix non-root prompts
     *
     * @param string $username Username
     * @param string $password Password
     * @throws TelnetException
     */
    public function login( $username, $password ){
        
        try{
            $this->read('Login:');
            $this->write((string) $username);
            $this->read('Password:');
            $this->write((string) $password);
            $this->read('OK');

        } catch( TelnetException $e ){
            throw new TelnetException('Login failed.', 0, $e);
        }
    }

    /**
     * Sets the string of characters to respond to.
     * This should be set to the last character of the command line prompt
     *
     * @param string $s String to respond to
     * @return boolean
     */
    public function setPrompt( $s = '$' ){
        $this->prompt = $s;
    }

    /**
     * Sets the string of characters to respond to.
     * This should be set to the last character of the command line prompt
     *
     * @param string $s String to respond to
     * @return boolean
     */
    public function setErrPrompt( $s = 'ERR' ){
        $this->errPrompt = $s;
    }

    /**
     * Gets character from the socket
     *
     * @return string
     */
    protected function getc(){
        $c = fgetc($this->socket);
        $this->globalBuffer .= $c;
        return $c;
    }

    /**
     * Clears internal command buffer
     *
     * @return void
     */
    public function clearBuffer(){
        $this->buffer = '';
    }

    /**
     * Returns true if binary mode is enabled
     */
    public function getBinaryMode(){
        return $this->binaryMode;
    }

    /**
     * Enables the binary mode
     *
     * @param bool $binaryMode
     */
    public function setBinaryMode( $binaryMode = true ){
        $this->binaryMode = $binaryMode;
    }

    /**
     * Reads characters from the socket and adds them to command buffer.
     * Handles telnet control characters. Stops when prompt is encountered.
     *
     * @param string $prompt
     * @param string $errPrompt
     * @return string
     * @throws TelnetException
     */
    protected function read( $prompt = NULL, $errPrompt = NULL ){
        if( !$this->socket ){
            throw new TelnetException('Telnet connection closed');
        }
        
        if( is_null($prompt) ){
            $prompt = $this->prompt;
        }
        
        if( is_null($errPrompt) ){
            $errPrompt = $this->errPrompt;
        }

        // clear the buffer
        $this->clearBuffer();

        $until_t = time() + $this->timeout;
        do {
            // time's up (loop can be exited at end or through continue!)
            if( time() > $until_t ){
                throw new TelnetException('Couldn\'t find the requested: "' . $prompt . '" within ' . $this->timeout . ' seconds');
            }

            $c = $this->getc();

            if( $c === false ){
                throw new TelnetException('Couldn\'t find the requested: "' . $prompt . '", it was not in the data returned from server: ' . $this->buffer);
            }

            // Interpreted As Command
            if( $c == $this->IAC ){
                if($this->negotiateTelnetOptions()){
                    continue;
                }
            }

            // append current char to global buffer
            $this->buffer .= $c;

            // we've encountered the prompt. Break out of the loop
            if( substr($this->buffer, strlen($this->buffer) - strlen($prompt)) == $prompt ){
                return substr($this->buffer, 0, strlen($this->buffer) - strlen($prompt));
            }elseif( strlen($errPrompt) && substr($this->buffer, strlen($this->buffer) - strlen($errPrompt)) == $errPrompt ){
                throw new TelnetException('Command has returned ERROR status');
            }

        }while( $c != $this->NULL || $c != $this->DC1 );

        return '';
    }

    /**
     * Reads the given amount of bytes
     *
     * @param int $count
     * @return string
     * @throws TelnetException
     */
    public function readBytes( $count ){
        if( !$this->socket ){
            throw new TelnetException('Telnet connection closed');
        }

        // clear the buffer
        $this->clearBuffer();

        while( $count ){
            $c = $this->getc();

            if( $c === false ){
                throw new TelnetException('Couldn\'t find the requested "' . $count . '" bytes, it was not in the data returned from server: ' . $this->buffer);
            }

            // Interpreted As Command
            if( $c == $this->IAC && !$this->binaryMode ){
                if( $this->negotiateTelnetOptions() ){
                    continue;
                }
            }

            // append current char to global buffer
            $this->buffer .= $c;

            $count -= strlen($c);
        }

        return $this->buffer;
    }

    /**
     * Write command to a socket
     *
     * @param string  $buffer     Stuff to write to socket
     * @param boolean $addNewLine Default true, adds newline to the command
     * @throws TelnetException
     */
    protected function write( $buffer, $addNewLine = true ){
        if( !$this->socket ){
            throw new TelnetException('Telnet connection closed');
        }

        // clear buffer from last command
        $this->clearBuffer();

        if( $addNewLine == true ){
            $buffer .= $this->CR;
        }

        $this->globalBuffer .= $buffer;
        if( !fwrite($this->socket, $buffer) < 0 ){
            throw new TelnetException('Error writing to socket');
        }
    }

    /**
     * Returns the content of the command buffer
     *
     * @return string Content of the command buffer
     */
    protected function getBuffer(){
            // cut last line (is always prompt)
            $buf = explode("\n", $this->buffer);
            unset($buf[count($buf) - 1]);
            $buf = implode("\n", $buf);
            return trim($buf);
    }

    /**
     * Returns the content of the global command buffer
     *
     * @return string Content of the global command buffer
     */
    public function getGlobalBuffer(){
            return $this->globalBuffer;
    }

    /**
     * Telnet control character magic
     *
     * @returns bool
     * @throws TelnetException
     */
    protected function negotiateTelnetOptions(){
        if( $this->binaryMode ){
            $b = $this->getGlobalBuffer();
            $this->buffer .= substr($b, -1);
            return;
        }

        $c = $this->getc();

        if( $c != $this->IAC ){
            if( $c == $this->DO || $c == $this->DONT ){
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->WONT . $opt);
            }else if(($c == $this->WILL) || ($c == $this->WONT)){
                $opt = $this->getc();
                fwrite($this->socket, $this->IAC . $this->DONT . $opt);
            }else{
                throw new TelnetException('Error: unknown control character ' . ord($c));
            }
        } else {
            throw new TelnetException('Error: Something Wicked Happened');
        }
    }
}
