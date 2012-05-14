<?php

namespace Objects\APIBundle\Controller;

/* load library. */
require_once __DIR__ . '/../libraries/abraham/twitteroauth.php';

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;
use OAuth\TwitterOAuth;

/**
 * @author mahmoud
 */
class TwitterController extends Controller {

    /**
     * the default Authentication route any new request to twitter has to go throw this action first
     * @param string $redirectRoute the route to redirect to after connecting to twitter
     */
    public function indexAction($redirectRoute) {
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
            //redirect the user to another action(signin or signup) to hide the parameters in the url
            return $this->redirect($this->generateUrl($session->get('redirectRoute'), array(), TRUE));
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
                exec('nohup ' . PHP_BINDIR . '/php ' . __DIR__ . '/../../../../app/console cache:clear -e prod > /dev/null 2>&1 &');
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
     * this fucntion will post the user twitts to twitter
     * @param string $status the status to post for user max 140 char
     * @return \Symfony\Component\HttpFoundation\Response success or fail
     */
    public function twittAction($status) {
        //get the container object
        $container = $this->container;
        //get a valid twitter connection of user
        $connection = new TwitterOAuth($container->getParameter('consumer_key'), $container->getParameter('consumer_secret'), $container->getParameter('oauth_token'), $container->getParameter('oauth_token_secret'));
        //get user data
        @$connection->post('statuses/update', array('status' => $status));
        //check if connection success with twitter
        if (200 == $connection->http_code) {
            //success twitt
            return new Response('success');
        } else {
            //failed to twitt
            return new Response('fail');
        }
    }

    /**
     * twitt any status to twitter
     * @param string $status the status to twitt
     * @param string $consumerKey
     * @param string $consumerSecret
     * @param string $oauthToken
     * @param string $oauthTokenSecret
     * @return boolean true for success and false for any fail
     */
    public static function twitt($status, $consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret) {
        //get a valid twitter connection of user
        $connection = new TwitterOAuth($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret);
        //get user data
        @$connection->post('statuses/update', array('status' => $status));
        //check if connection success with twitter
        if (200 == $connection->http_code) {
            //success twitt
            return TRUE;
        } else {
            //failed to twitt
            return FALSE;
        }
    }

    /**
     * this function will return the last $count twitted twitts
     * @param integer $count the number of twitts to retrieve
     * @return \Symfony\Component\HttpFoundation\Response the twitts with the js library to display them
     */
    public function getLastTwittsAction($count) {
        //get the container object
        $container = $this->container;
        //get the request object
        $request = $this->getRequest();
        //create a new response for the user
        $response = new Response();
        //set the caching to every one
        $response->setPublic();
        //this date is used as an etag
        $date = new \DateTime();
        //set the response ETag
        $response->setETag($date->format('dH'));
        // Check that the Response is not modified for the given Request
        if ($response->isNotModified($request)) {
            // return the 304 Response immediately
            return $response;
        }
        //the response will be valid for next 6 hours
        $response->setSharedMaxAge(21600);
        //the twitts ids array
        $twittsIds = array();
        //get a valid twitter connection of user
        $connection = new TwitterOAuth($container->getParameter('consumer_key'), $container->getParameter('consumer_secret'), $container->getParameter('oauth_token'), $container->getParameter('oauth_token_secret'));
        //get user twitts
        $twitts = @$connection->get('statuses/user_timeline', array('count' => $count));
        //check if it is a success request
        if (200 == $connection->http_code) {
            foreach ($twitts as $twitt) {
                //add the twitt id to the array of twitts
                $twittsIds [] = $twitt->id_str;
            }
            //this flag is for adding the twitter js script tag only once
            $set = FALSE;
            foreach ($twittsIds as $twittId) {
                //check if this is the first element
                if (!$set) {
                    //first element add the js library to the response
                    $response->setContent('<script src="//platform.twitter.com/widgets.js" charset="utf-8"></script>');
                    $set = TRUE;
                }
                //request the twitts formated
                $twitts = @$connection->get('statuses/oembed', array('id' => $twittId, 'omit_script' => TRUE));
                //check if connection success with twitter
                if (200 == $connection->http_code) {
                    //add the twitt content to the response
                    $response->setContent($response->getContent() . $twitts->html);
                }
            }
        }
        //return the response
        return $response;
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
