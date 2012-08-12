<?php

namespace Objects\APIBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/../libraries/linkedIn/linkedin_3.2.0.class.php';

/**
 * @author Ahmed <a.ibrahim@objects.ws>
 */
class LinkedinController extends Controller {

    /**
     * this function used to get all linkedIn user data
     * @author Ahmed <a.ibrahim@objects.ws>
     * @param string $appKey
     * @param string $appSecret
     * @param array $linkedIn_oauth array of user oauth data
     */
    public static function getUserData($appKey, $appSecret, $linkedIn_oauth) {
        //linkedIn config parameters
        $config = array('appKey' => $appKey,
            'appSecret' => $appSecret,
            'callbackUrl' => '');
        //create new linkedIn oauth object
        $oauth = new \LinkedIn($config);
        $oauth->setTokenAccess($linkedIn_oauth);
        $userData = $oauth->profile('~:(id,first-name,last-name,picture-url,headline,site-standard-profile-request,location:(country:(code)),summary,positions,skills,educations,courses)');

        //check if connection success with twitter
        if (200 == $userData['info']['http_code']) {
            return $userData;
        } else {
            return NULL;
        }
    }

    /**
     * this function used to get user access token after authentication
     * @author Ahmed <a.ibrahim@objects.ws>
     */
    public function linkedInCallBackAction() {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the translator object
        $translator = $this->get('translator');
        //linkedIn config parameters
        $config = array('appKey' => $this->container->getParameter('linkedin_api_key'),
            'appSecret' => $this->container->getParameter('linkedin_secret_key'),
            'callbackUrl' => '');
        //create new linkedIn oauth object
        $oauth = new \LinkedIn($config);
        //get user access token
        $access_token = $oauth->retrieveTokenAccess($request->get('oauth_token'), $session->get('oauth_token_secret'), $request->get('oauth_verifier'));

        /* If HTTP response is 200 continue otherwise send to connect page to retry */
        if (200 == $access_token['info']['http_code']) {
            /* The user has been verified store the data in the session */
            $session->set('oauth_linkedin', $access_token['linkedin']);
            $session->set('oauth_token', $access_token['linkedin']['oauth_token']);
            $session->set('oauth_token_secret', $access_token['linkedin']['oauth_token_secret']);
            //check if this is a popup
            if ($session->get('linkedInPopup', FALSE)) {
                //remove the flag
                $session->remove('linkedInPopup');
                //redirect the parent window and then close the popup
                return new Response('
                    <script>
                        window.opener.top.location.href = "' . $this->generateUrl('linkedIn_user_data', array(), TRUE) . '";
                        self.close();
                    </script>
                    ');
            }
            //redirect the user to linkedInUserDataAction te get user data
            return $this->redirect($this->generateUrl('linkedIn_user_data', array(), TRUE));
        } else {
            //something went wrong go to connect page again
            $session->clear();
            return new Response($translator->trans('linkedIn connection error') . ' <a href="' . $this->generateUrl('linkedInButton', array('callbackUrl' => 'linkedInCallBack'), TRUE) . '">' . $translator->trans('try again') . '</a>');
        }
    }

    /**
     * this function used to authentication/authorisation from the user to the applecation
     * @author Ahmed <a.ibrahim@objects.ws>
     * @param string $callbackUrl
     */
    public function linkedInButtonAction($callbackUrl, $popup) {
        //linkedIn config parameters
        $config = array('appKey' => $this->container->getParameter('linkedin_api_key'),
            'appSecret' => $this->container->getParameter('linkedin_secret_key'),
            'callbackUrl' => $this->generateUrl($callbackUrl, array(), TRUE));
        //create new linkedIn oauth object
        $oauth = new \LinkedIn($config);
        //get request token
        $request_token = @$oauth->retrieveTokenRequest();
        $session = $this->getRequest()->getSession();
        $session->set('oauth_token', $request_token['linkedin']['oauth_token']);
        $session->set('oauth_token_secret', $request_token['linkedin']['oauth_token_secret']);
        $session->set('callbackUrl', $callbackUrl);
        //check if we will set the popup flag
        if ($popup == 'yes') {
            //set the flag
            $session->set('linkedInPopup', TRUE);
        }
        // redirect the user to the LinkedIn authentication/authorisation page.
        $url = \LINKEDIN::_URL_AUTH . $request_token['linkedin']['oauth_token'];
        return $this->redirect($url);
    }

    /**
     * this function download an image from linkedIn to the user required directory
     * @author Ahmed <a.ibrahim@objects.ws>
     * @param string $imageUrl the linkedIn image url
     * @param string $uploadDir full path to the directory to save the downloaded image in without trailing '/'
     * @return string|boolean the image name on success or FALSE on fail
     */
    public static function downloadLinkedInImage($imageUrl, $uploadDir) {
        //check if the upload directory exists
        if (!@is_dir($uploadDir)) {
            //get the old umask
            $oldumask = umask(0);
            //not a directory probably the first time for this category try to create the directory
            $success = @mkdir($uploadDir, 0755, TRUE);
            //reset the umask
            umask($oldumask);
            //check if we created the folder
            if (!$success) {
                //could not create the folder
                return FALSE;
            }
        }
        //generate a random image name
        $img = uniqid();
        $extension = 'jpg';
        //check that the file name does not exist
        while (@file_exists("$uploadDir/$img.$extension")) {
            //try to find a new unique name
            $img = uniqid();
        }
        //download the large picture from the url to stream
        $fileContent = @file_get_contents($imageUrl);
        //check if we got the image content
        if ($fileContent !== FALSE) {
            //save the image on the server
            $inserted = @file_put_contents("$uploadDir/$img.$extension", $fileContent);
            //check if the image saved
            if ($inserted !== FALSE) {
                //return the image name
                return "$img.$extension";
            }
        }

        //could not download the image
        return FALSE;
    }

    /**
     * this function used to share html post
     * @author Ahmed <a.ibrahim@objects.ws>
     * @param string $appKey
     * @param string $appSecret
     * @param string $user_oauth_token
     * @param string $user_oauth_token_secret
     * @param string $comment 'empty' if not
     * @param string $title 'empty' if not
     * @param string $submittedUrl 'empty' if not
     * @param string $submittedImageUrl 'empty' if not
     * @param string $description 'empty' if not
     * @return response done on success or faild on faild
     */
    static function linkedInShare($appKey, $appSecret, $user_oauth_token, $user_oauth_token_secret, $comment, $title, $description, $submittedUrl, $submittedImageUrl) {
        //linkedIn config parameters
        $config = array('appKey' => $appKey,
            'appSecret' => $appSecret,
            'callbackUrl' => '');
        //create new linkedIn oauth object
        $oauth = new \LinkedIn($config);
        $linkedIn_oauth = array('oauth_token' => $user_oauth_token, 'oauth_token_secret' => $user_oauth_token_secret);
        //set user token
        $oauth->setTokenAccess($linkedIn_oauth);

        // prepare content for sharing
        $content = array();
        if ($comment != 'empty') {
            $content['comment'] = $comment;
        }
        if ($title != 'empty') {
            $content['title'] = $title;
        }
        if ($submittedUrl != 'empty') {
            $content['submitted-url'] = $submittedUrl;
        }
        if ($submittedImageUrl != 'empty') {
            $content['submitted-image-url'] = $submittedImageUrl;
        }
        if ($description != 'empty') {
            $content['description'] = $description;
        }

        // share content
        $response = $oauth->share('new', $content, FALSE);

        if ($response['success'] === TRUE) {
            // status has been updated
            return new Response('done');
        } else {
            // an error occured
//            echo "Error posting network update:<br /><br />RESPONSE:<br /><br /><pre>" . print_r($response, TRUE) . "</pre><br /><br />LINKEDIN OBJ:<br /><br /><pre>" . print_r($OBJ_linkedin, TRUE) . "</pre>";
            return new Response('faild');
        }
    }

    /**
     * this function will used to send string post
     * @param string $appKey
     * @param string $appSecret
     * @param string $user_oauth_token
     * @param string $user_oauth_token_secret
     * @param string $post
     * @return response done on success or faild on faild
     */
    static function linkedInPostUpdate($appKey, $appSecret, $user_oauth_token, $user_oauth_token_secret, $post) {
        //linkedIn config parameters
        $config = array('appKey' => $appKey,
            'appSecret' => $appSecret,
            'callbackUrl' => '');
        //create new linkedIn oauth object
        $oauth = new \LinkedIn($config);
        $linkedIn_oauth = array('oauth_token' => $user_oauth_token, 'oauth_token_secret' => $user_oauth_token_secret);
        //set user token
        $oauth->setTokenAccess($linkedIn_oauth);

        $response = $oauth->updateNetwork($post);
        if ($response['success'] === TRUE) {
            // status has been updated
            return new Response('done');
        } else {
            // an error occured
//            echo "Error posting network update:<br /><br />RESPONSE:<br /><br /><pre>" . print_r($response, TRUE) . "</pre><br /><br />LINKEDIN OBJ:<br /><br /><pre>" . print_r($OBJ_linkedin, TRUE) . "</pre>";
            return new Response('faild');
        }
    }

}
