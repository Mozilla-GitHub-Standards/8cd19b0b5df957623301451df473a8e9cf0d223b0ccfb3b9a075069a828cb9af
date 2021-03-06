<?php defined('SYSPATH') or die('No direct script access.');

class Controller_Filing extends Controller_Template {

    private $non_authed_areas = array(
        'authenticate::login',
        'authenticate::logout'
    );

    // label        path
    protected $static_crumb_base = array('web forms' => '/');

    public function __construct(Kohana_Request $request) {
        parent::__construct($request);
        // if we are in dev(stage), send the httpauth credentials to Bugzilla_Client
        $httpauth_credentials = null;
        if(Kohana::config('workermgmt.in_dev_mode')) {
            $httpauth_credentials = Httpauth::credentials();
        }
        $this->bugzilla_client = new Bugzilla_Client(
            Kohana::config('workermgmt'), $httpauth_credentials
        );
        $requested_area = strtolower($this->request->controller."::".$this->request->action);
        if( ! in_array($requested_area, $this->non_authed_areas)) {
            // run authentication
            if( ! $this->bugzilla_client->authenticated()) {
            	$this->request->redirect('authenticate/login'); // TODO: UNCOMMENT
            }
        }
    }

    /**
     * works well for structured portions of the site (like admin interfaces)
     *
     * @return array
     */
    protected function auto_crumb() {

        $crumbs = isset($this->static_crumb_base)&&$this->static_crumb_base
            ? array($this->static_crumb_base)
            : array();
        /*
         * build | base / controller / action
         */
        $crumb_base_path = current($this->static_crumb_base);
        $crumb_base_path == '/' ? '' : $crumb_base_path;
        if(!empty($this->request->controller)) {
            $controller_path = "{$crumb_base_path}/{$this->request->controller}";
            array_push($crumbs, array(str_replace('_', " ", $this->request->controller) => $controller_path));
        }
        if(!empty($this->request->action) && strtolower($this->request->action) !='index' ) {
            $action_path = "{$controller_path}/{$this->request->action}";
            array_push($crumbs, array(str_replace('_', " ", $this->request->action) => $action_path));
        }
        // de-link the tail
        array_push($crumbs,array(key(array_pop($crumbs))));
        return $crumbs;
    }

    /**
     * Submit these bug types using the validated from data
     *
     * @param array $bugs_to_file Must be known values of Bugzilla
     *      i.e. Bugzilla::BUG_NEWHIRE_SETUP, Bugzilla::BUG_HR_CONTRACTOR, ...
     * @param array $form_input The validated form input
     */
    protected function file_these(array $bugs_to_file, $form_input) {
        $success = array();
        $filing = array();
        foreach ($bugs_to_file as $bug_to_file) {
            try {
                $filing = Filing::factory($bug_to_file, $form_input, $this->bugzilla_client);
                $filing->file();
                $bug_link = sprintf("<a href=\"%s/show_bug.cgi?id=%d\" target=\"_blank\">bug %d</a>",
                    $this->bugzilla_client->config('bugzilla_url'),
                    $filing->bug_id,
                    $filing->bug_id
                );
                Client::messageSend(
                    str_replace(
                        array('{label}','{bug}'),
                        array($filing->label, $bug_link),
                        $filing->success_message),
                    E_USER_NOTICE
                );
                $success[] = $filing->bug_id;
            } catch (Exception $e) {
                /**
                 * Timed out session most likely
                 */
                if($e->getCode()==Filing::EXCEPTION_AUTHENTICATION_FAILED) {
                    client::messageSend('Authentication Failed, need to re-login', E_USER_ERROR);
                    $this->request->redirect('authenticate/login');
                /**
                 * either the supplied $submitted_data to the Filing instance
                 * was missing or construct_content() method of the Filing
                 * instance tried to access a submitted content key that did
                 * not exist.
                 */
                } else if($e->getCode()==Filing::EXCEPTION_MISSING_INPUT) {
                    Kohana_Log::instance()->add('error',__METHOD__." {$e->getMessage()}");
                    Client::messageSend('Missing required input to build this Bug', E_USER_ERROR);
                /**
                 * bug was constructed successfully but we got an error back
                 * when we sent it to Bugzilla
                 */
                } else if($e->getCode()==Filing::EXCEPTION_BUGZILLA_INTERACTION) {
                    Kohana_Log::instance()->add('error',__METHOD__." {$e->getMessage()}");
                    Client::messageSend("There was an error communicating "
                        ."with the Bugzilla server for Bug \"{$filing->label}\": {$e->getMessage()}", E_USER_ERROR);
                /**
                 * something happend, log it and toss it
                 */
                } else {
                    Kohana_Log::instance()->add('error',__METHOD__." {$e->getMessage()}\n{$e->getTraceAsString()}");
                    Client::messageSend('Unknown exception when filing this bug', E_USER_ERROR);
                    throw $e;
                }
            }
        }
        return $success;
    }
}