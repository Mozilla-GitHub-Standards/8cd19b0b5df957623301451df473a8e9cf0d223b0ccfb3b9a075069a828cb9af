<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * Authentication Controller
 * 
 * Auths against bugzilla and stores the auth cookies to be used to submit bugs
 * for the various Hiring forms.
 *
 *
 */
class Controller_Authenticate extends Controller_Template {

    public function  __construct(Kohana_Request $request) {
        $this->bugzilla = Bugzilla::instance(Kohana::config('workermgmt'));
        parent::__construct($request);
    }  
    public function action_index() {
        url::redirect('login'); 
    }
    /**
     * note: Route set to /login
     */
    public function action_login() {
        $username = $this->input->post('bz_username');
        $password = $this->input->post('bz_password');
        if($_POST) {
            $validation = Validation::factory($this->input->post())
                ->pre_filter('trim')
                ->add_rules('bz_username', 'required')
                ->add_rules('bz_password', 'required')
            ;
            if($validation->validate()) {
                if($this->bugzilla->login($username,$password)) {
                    url::redirect();
                } else {
                    client::messageSend($this->bugzilla->error_message(), E_USER_WARNING);
                }
            } else {
                client::validation_results($validation->errors());
                client::messageSend("There were errors in some fields", E_USER_WARNING);
            }
        }
        $this->template->content = new View('pages/bz_login');
        $this->template->content->bz_username = $username;
        $this->template->content->bz_password = $password;
        $this->template->title = 'Worker Managment :: Login';

    }
    /**
     * note: Route set to /logout
     */
    public function action_logout() {
        $this->bugzilla->logout();
        client::messageSend("You have logged out", E_USER_NOTICE);
        url::redirect('login');
    }

}