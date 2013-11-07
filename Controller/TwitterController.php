<?php

namespace Objects\APIBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;
use TwitterOAuth\Api as TwitterOAuth;

/**
 * @author mahmoud
 */
class TwitterController extends Controller {

    /**
     * the default Authentication route any new request to twitter has to go throw this action first
     * @param string $redirectRoute the route to redirect to after connecting to twitter
     * @param string $popup if set to "yes" the twitter action will attempt to close a popup and redirect it is parent instade of redirect
     */
    public function indexAction($redirectRoute, $popup = 'no') {
        //get the session object
        $session = $this->getRequest()->getSession();
        //get the translator
        $translator = $this->get('translator');
        //get the container
        $container = $this->container;
        /* Build TwitterOAuth object with client credentials. */
        $connection = new TwitterOAuth($container->getParameter('consumer_key'), $container->getParameter('consumer_secret'));

        /* Get temporary credentials. */
        $request_token = @$connection->getRequestToken($this->generateUrl('twitter_callback', array(), TRUE));

        /* If last connection failed don't display authorization link. */
        switch ($connection->http_code) {
            case 200:
                /* Save temporary credentials to session. */
                $session->set('oauth_token', $request_token['oauth_token']);
                $session->set('oauth_token_secret', $request_token['oauth_token_secret']);
                $session->set('redirectRoute', $redirectRoute);
                //check if we will set the popup flag
                if ($popup == 'yes') {
                    //set the flag
                    $session->set('twitterPopup', TRUE);
                }

                /* Build authorize URL and redirect user to Twitter. */
                $url = $connection->getAuthorizeURL($request_token['oauth_token']);
                return $this->redirect($url);
            default:
                /* Show notification if something went wrong. */
                $session->clear();
                return new Response($translator->trans('twitter connection error') . ' <a href="' . $this->generateUrl('twitter_authentication', array('redirectRoute' => $redirectRoute), TRUE) . '">' . $translator->trans('try again') . '</a>');
        }
    }

    /**
     * the call back url that twitter will access
     * on success redirect to another action(signin or signup)
     * and puts the user oauth_token, oauth_token_secret and twitter id in the session
     */
    public function twitterAction() {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the container object
        $container = $this->container;
        //get the translator object
        $translator = $this->get('translator');
        /* Create TwitteroAuth object with app key/secret and token key/secret from default phase */
        $connection = new TwitterOAuth($container->getParameter('consumer_key'), $container->getParameter('consumer_secret'), $session->get('oauth_token'), $session->get('oauth_token_secret'));

        /* Request access tokens from twitter */
        $access_token = $connection->getAccessToken($request->get('oauth_verifier'));

        /* If HTTP response is 200 continue otherwise send to connect page to retry */
        if (200 == $connection->http_code) {
            /* The user has been verified store the data in the session */
            $session->set('oauth_token', $access_token['oauth_token']);
            $session->set('oauth_token_secret', $access_token['oauth_token_secret']);
            $session->set('twitterId', $access_token['user_id']);
            $session->set('screen_name', $access_token['screen_name']);
            //check if this is a popup
            if ($session->get('twitterPopup', FALSE)) {
                //remove the flag
                $session->remove('twitterPopup');
                //redirect the parent window and then close the popup
                return new Response('
                    <script>
                        window.opener.top.location.href = "' . $this->generateUrl($session->get('redirectRoute')) . '";
                        self.close();
                    </script>
                    ');
            }
            //redirect the user to another action(signin or signup) to hide the parameters in the url
            return $this->redirect($this->generateUrl($session->get('redirectRoute')));
        } else {
            //something went wrong go to connect page again
            $session->clear();
            return new Response($translator->trans('twitter connection error') . ' <a href="' . $this->generateUrl('twitter_authentication', array('redirectRoute' => $session->get('redirectRoute')), TRUE) . '">' . $translator->trans('try again') . '</a>');
        }
    }

    /**
     * this action will save the user twitter tokens from the session in the configuration file
     */
    public function saveTwitterTokensAction() {
        //get the request object
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //get the translator object
        $translator = $this->get('translator');
        //the configuration file path
        $configFile = __DIR__ . '/../Resources/config/config.yml';
        //create a new yaml parser
        $yaml = new Parser();
        //try to open the configuration file
        $content = @file_get_contents($configFile);
        //check if we opened the file
        if ($content !== FALSE) {
            //file opened try to parse the content
            try {
                //try to get the file data
                $value = $yaml->parse($content);
            } catch (\Exception $e) {
                // an error occurred during parsing
                return new Response('Unable to parse the YAML string: ' . $e->getMessage());
            }
            //set the tokens in array of parameters
            $value['parameters']['oauth_token'] = $session->get('oauth_token');
            $value['parameters']['oauth_token_secret'] = $session->get('oauth_token_secret');
            //create a new yaml dumper
            $dumper = new Dumper();
            $yaml = $dumper->dump($value, 3);
            //try to put the data dump into the file
            if (@file_put_contents($configFile, $yaml) !== FALSE) {
                //clear the cache for the new configurations to take effect
                exec(PHP_BINDIR . '/php-cli ' . __DIR__ . '/../../../../app/console cache:clear -e prod');
                exec(PHP_BINDIR . '/php-cli ' . __DIR__ . '/../../../../app/console cache:warmup --no-debug -e prod');
                //set the success message
                $message = $translator->trans('saved successfully');
            } else {
                //an error occured while writing to the file might be a permission error
                $message = $translator->trans('write error') . ": $configFile";
            }
        } else {
            // an error occurred during parsing
            $message = $translator->trans('read error') . ": $configFile";
        }
        return new Response($message);
    }

    /**
     * tweet any status to twitter
     * @param string $status the status to tweet
     * @param string $consumerKey
     * @param string $consumerSecret
     * @param string $oauthToken
     * @param string $oauthTokenSecret
     * @return boolean true for success and false for any fail
     */
    public static function tweet($status, $consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret) {
        //get a valid twitter connection of user
        $connection = new TwitterOAuth($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret);
        //get user data
        @$connection->post('statuses/update', array('status' => $status));
        //check if connection success with twitter
        if (200 == $connection->http_code) {
            //success tweet
            return TRUE;
        } else {
            //failed to tweet
            return FALSE;
        }
    }


    /**
     * get the user data
     * @param string $consumerKey
     * @param string $consumerSecret
     * @param string $oauthToken the user token
     * @param string $oauthTokenSecret the user token secret
     * @return mixed null or data of user
     */
    public static function getCredentials($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret) {
        //get a valid twitter connection of user
        $connection = new TwitterOAuth($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret);
        //get user data
        $content = @$connection->get('account/verify_credentials');
        //check if connection success with twitter
        if (200 == $connection->http_code) {
            return $content;
        } else {
            return NULL;
        }
    }

    /**
     * get the user following accounts
     * @param string $consumerKey
     * @param string $consumerSecret
     * @param string $oauthToken the user token
     * @param string $oauthTokenSecret the user token secret
     * @param string the userId
     * @return mixed null or a list of user following ids
     */
    public static function getFollowing($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret, $userId) {
        //get a valid twitter connection of user
        $connection = new TwitterOAuth($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret);
        //get user data
        $content = @$connection->get('friends/ids', array('user_id' => $userId));
        //check if connection success with twitter
        if (200 == $connection->http_code) {
            //check if we got any response
            if ($content) {
                //check if we have any ids
                if (isset($content->ids)) {
                    return $content->ids;
                } else {
                    return $content;
                }
            }
        }
        return NULL;
    }

    /**
     * this function download an image from twitter to the user required directory
     * @author Mahmoud
     * @param string $imageUrl the twitter image url
     * @param string $uploadDir full path to the directory to save the downloaded image in without trailing '/'
     * @return string|boolean the image name on success or FALSE on fail
     */
    public static function downloadTwitterImage($imageUrl, $uploadDir) {
        //get the url parts
        $urlParts = explode('/', $imageUrl);
        //check if the url is correct
        if ($urlParts && (count($urlParts) > 1)) {
            //get the image name
            $imageName = array_pop($urlParts);
            //check if it is a default profile image
            $pos = strpos($imageName, 'default_profile');
            if ($pos === FALSE) {
                //determine the image extension
                $urlParts = explode('.', $imageUrl);
                //check if the url is correct
                if ($urlParts && (count($urlParts) > 1)) {
                    //get the image extension from the url
                    $extension = array_pop($urlParts);
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
                    //check that the file name does not exist
                    while (@file_exists("$uploadDir/$img.$extension")) {
                        //try to find a new unique name
                        $img = uniqid();
                    }
                    //download the large picture from the url to stream
                    $fileContent = @file_get_contents(preg_replace('/_normal/', '', $imageUrl));
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
                }
            }
        }
        //could not download the image
        return FALSE;
    }

}
