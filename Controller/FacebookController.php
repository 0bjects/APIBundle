<?php

namespace Objects\APIBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

/**
 * @author mirehan
 */
class FacebookController extends Controller {

    /**
     * this action take 
     * @param Request $request
     * @param string $facebookUserHandleRoute (route that will handle facebook user)
     * @param string $permissions (facebook permissions require dseparated by ,)
     * @param string $cssClass (css class for designer to add desired image)
     * @param string $linkText (text written in the link)
     * @return html facebook link with desired css class and text

     */
    public function facebookButtonAction($facebookUserHandleRoute, $permissions, $cssClass='', $linkText = '') {

        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //set facebookUserHandleRoute in session (route of action that will go to after 
        //action action that set user and its token in session
        $session->set('facebookUserHandleRoute', $facebookUserHandleRoute);

        //get the container
        $container = $this->container;
        //redirect url after dialog is finished
        $endDialogUrl = $this->generateUrl('facebook_end_dailog', array(), true);
        //url to open facebook dialog 
        $dialog_url = 'https://www.facebook.com/dialog/oauth?'
                . 'client_id=' . $container->getParameter('fb_app_id')
                . '&redirect_uri=' . urlencode($endDialogUrl)
                . '&scope=' . $permissions
                . '&display=popup'
                . '&state=' . $container->getParameter('fb_app_state');

        return $this->render('ObjectsAPIBundle:Facebook:facebookLink.html.twig', array('dialog_url' => $dialog_url, 'cssClass' => $cssClass, 'text' => $linkText));
    }

    /**
     * at the end of facebook dialog facebook redirect to this action
     * this action get access token of the logged user and then get facebook user
     * set the access token and the user in the session
     * @return script to redirect the original window to the action that will handle 
     * the facebook user in the session and close facebook dialog popup 
     */
    public function endDialogAction() {
        $request = $this->getRequest();
        //get the session object
        $session = $request->getSession();
        //code come from facebook dialog that will be used to get the token
        $code = $request->query->get('code');
        $error = $request->query->get('error_reason');

        $facebookUserHandleRoute = $session->get('facebookUserHandleRoute');

        //get access token using code from facebook
        if (isset($error)) {
            $session->set('facebook_short_live_access_token', null);
            $session->set('facebook_user', null);
            $session->set('facebook_error', 'FACEBOOK_ERROR');
        } else {
            //get the container
            $container = $this->container;

            $my_url = $this->generateUrl('facebook_end_dailog', array(), true);

            //get short-live access token this is the one that will be stored in the session 
            //and also we can get long-live access token from it
            if ($request->query->get('state') == $container->getParameter('fb_app_state')) {
                $token_url = 'https://graph.facebook.com/oauth/access_token?'
                        . 'client_id=' . $container->getParameter('fb_app_id') . '&redirect_uri=' . urlencode($my_url)
                        . '&client_secret=' . $container->getParameter('fb_app_secret') . '&code=' . $code;

                $response = @file_get_contents($token_url);
                $params = null;
                parse_str($response, $params);
                if ($params['access_token']) {
                    $session->set('facebook_error', null);
                    $session->set('facebook_short_live_access_token', $params['access_token']);
                    //get user and set it in session
                    $graph_url = 'https://graph.facebook.com/me?access_token=' . $params['access_token'];
                    $faceUser = json_decode(file_get_contents($graph_url));
                    $session->set('facebook_user', $faceUser);
                } else {
                    $session->set('facebook_short_live_access_token', null);
                    $session->set('facebook_user', null);
                    $session->set('facebook_error', 'ACCESS_TOKEN_ERROR');
                }
            } else {
                $session->set('facebook_short_live_access_token', null);
                $session->set('facebook_user', null);
                $session->set('facebook_error', 'MISMATCH_FACEBOOK_STATE');
            }
            return $this->render('ObjectsAPIBundle:Facebook:facebookCloseWindow.html.twig', array('url' => $this->generateUrl($facebookUserHandleRoute, array(), true)));
        }
    }

    /**
     * the action is to handle one user data (admin) user
     * @return type 
     */
    public function facebookOneUserHandelerAction() {
        $request = $this->getRequest();

        $session = $this->get('session');
        //get the translator
        $translator = $this->get('translator');

        $faceuser = $session->get('facebook_user');
        $shortLive_access_token = $session->get('facebook_short_live_access_token');
        if ($shortLive_access_token) {
            //using the short-live access token get the long-live one
            $params = $this->getLongLiveFaceboockAccessToken($this->container->getParameter('fb_app_id'), $this->container->getParameter('fb_app_secret'), $shortLive_access_token);
            // long live access token
            $longLive_access_token = $params['access_token'];

            $fb_user_id = $faceuser->id;
            //get the user required page from the configuration file
            $userPageName = $this->container->getParameter('fb_page_name');
            //now we need access token for this page
            //get the admin accounts and search for this page
            $pages = $this->adminUserPagesAccess($fb_user_id, $longLive_access_token);
            //decode the data
            $pagesData = json_decode($pages);
            //initialize the page found flag
            $found = FALSE;
            //try to find the user required page
            foreach ($pagesData->data as $page) {
                //check if this page is the user requested page
                if ($page->name == $userPageName) {
                    //page found mark the flag
                    $found = TRUE;
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
                            return $this->render('::general_admin.html.twig', array(
                                        'message' => 'Unable to parse the YAML string: ' . $e->getMessage()
                                    ));
                        }
                        //set the tokens in array of parameters
                        $value['parameters']['fb_user_id'] = $fb_user_id;
                        //save long-live access token in config file
                        $value['parameters']['fb_access_token'] = $longLive_access_token;
                        $value['parameters']['fb_access_token_expiration_date'] = date('d-m-Y', time() + $params['expires']);
                        $value['parameters']['fb_page_access_token'] = $page->access_token;
                        $value['parameters']['fb_page_id'] = $page->id;
                        //create a new yaml dumper
                        $dumper = new Dumper();
                        $yaml = $dumper->dump($value, 3);
                        //try to put the data dump into the file
                        if (@file_put_contents($configFile, $yaml) !== FALSE) {
                            //clear the cache for the new configurations to take effect
                            exec('nohup ' . PHP_BINDIR . '/php ' . __DIR__ . '/../../../../app/console cache:clear -e prod > /dev/null 2>&1 &');
                            //set the success flag
                            $session = $request->getSession();
                            $session->setFlash('notice', 'Your configurations were saved');
                            //redirect the user to another action(signin or signup) to hide the parameters in the url
                            return $this->redirect($session->get('currentLocationUrl'));
                        } else {
                            //an error occured while writing to the file might be a permission error
                            $message = "Could not write in the file: $configFile";
                            break;
                        }
                    } else {
                        // an error occurred during parsing
                        $message = "Unable to open the YAML file: $configFile";
                        break;
                    }
                }
            }
            //check if we found the user page
            if (!$found) {
                //not found
                $message = 'the page requested is not correct please go to the <a href="' . $session->get('currentLocationUrl') . '">configurations page</a> and edit fb page name';
            }
        } else {
            $message = 'invalid access token';
        }

        return $this->render('::general_admin.html.twig', array(
                    'message' => $message
                ));
    }

    /**
     * method that take valid short-live access token that we get from facebook dialog
     * and The returned access_token will have a fresh long-lived expiration time, 
     * however, the access_token itself may or may not be the same as 
     * the previously granted long-lived access_token.
     * @param type $shortLive_access_token 
     */
    public static function getLongLiveFaceboockAccessToken($appId, $appSecret, $shortLive_access_token) {
        // get long live access token using short live access token
        $token_url = 'https://graph.facebook.com/oauth/access_token?'
                . 'client_id=' . $appId
                . '&client_secret=' . $appSecret
                . '&grant_type=fb_exchange_token'
                . '&fb_exchange_token=' . $shortLive_access_token;

        $response = @file_get_contents($token_url);
        $params = null;
        parse_str($response, $params);
        return $params;
    }

    /**
     * method that check that the page is in user page that adminstriate them
     *
     * @param type $userFacebookAccountId 
     * @param type $accessToken (valid access token)
     * @param type $pageName
     * @return type 
     */
    public static function CheckAdminUserPage($userFacebookAccountId, $accessToken, $pageName) {
        $query = "SELECT+page_id,name+from+page+WHERE+name='%s'+AND+page_id+IN+(SELECT+page_id+from+page_admin+WHERE+uid=%s)";
        $q = sprintf($query, $pageName, $userFacebookAccountId);
        $fql_query_url = 'https://graph.facebook.com/fql?q=' . $q . '&access_token=' . $accessToken;
        $fql_query_result = file_get_contents($fql_query_url);
        $fql_query_obj = json_decode($fql_query_result, true);
        if (empty($fql_query_obj)) {
            return false;
        }
        return true;
    }

    /**
     * method to post on page/app wall
     * @author Mirehan
     */
    public function postOnAppWallAction($message) {
        $pageAccessToken = $this->container->getParameter('fb_page_access_token');
        $pageId = $this->container->getParameter('fb_page_id');
        $fieldsString = "access_token=$pageAccessToken&message=$message";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/$pageId/feed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * method to post on page/app wall
     * @author Mirehan
     */
    public static function postOnAppWall($message, $pageAccessToken, $pageId) {
        $fieldsString = "access_token=$pageAccessToken&message=$message";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/$pageId/feed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * method to retrieve access_tokens for pages and application the user administrates on facebook 
     * @author Mirehan
     * @param type $accessToken user access_token
     * @param type $userFacebookAccountId
     * @return type array of objects containing account name, access_token, category, id
     */
    public static function adminUserPagesAccess($userFacebookAccountId, $accessToken) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://graph.facebook.com/' . $userFacebookAccountId . '/accounts?access_token=' . $accessToken);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * this action returns the facebook application id
     * used to render in any iframe that needs the application id
     * @author Mahmoud
     * @return \Symfony\Component\HttpFoundation\Response the facebook application id
     */
    public function getFacbookApplicationIdAction() {
        return new Response($this->container->getParameter('fb_app_id'));
    }

    /**
     * static method that post on user wall
     * @author Mirehan
     * @param type $accessToken 
     * @param type $message
     * @param type $picture
     * @param type $link
     * @return Response 
     */
    public static function postOnUserWallAndFeedAction($accountId,$accessToken, $message, $name,$description, $link, $picture) {

        $fieldsString = "access_token=$accessToken&message=$message&name=$name&picture=$picture";
        $fieldsString.="&link=$link&description=$description";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/$accountId/feed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);
        curl_exec($ch);
        curl_close($ch);
        return new Response('postOnUserWallAndFeed');
    }

    /**
     *
     * @param type $faceboockAccountId
     * @param type $uploadDir 
     */
    public static function downloadAccountImage($faceboockAccountId, $uploadDir) {
        //download facebook profile image and saving it into db
        //extracting the image extension from the url
        $photoUrl = "http://graph.facebook.com/$faceboockAccountId/picture?type=large";
        
        //get the real url for picture to extract picture extension
         $options = array(
            CURLOPT_RETURNTRANSFER => true, // return web page
            CURLOPT_HEADER => false, // don't return headers
            CURLOPT_FOLLOWLOCATION => true, // follow redirects
        );
        $ch = curl_init($photoUrl);
        curl_setopt_array($ch, $options);
        $content = curl_exec($ch);
        $header = curl_getinfo($ch);
        curl_close($ch);
        $imageRealUrl = $header['url'];
        $urlParts = explode("/", $imageRealUrl);
       
        //get the image extension from the url
        $extension = array_pop($urlParts);
        //mahmoud
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
        $imageContent = @file_get_contents($photoUrl);
        //check if we got the image content
        if ($imageContent !== FALSE) {
            //save the image on the server
            $inserted = @file_put_contents("$uploadDir/$img.$extension", $imageContent);
            //check if the image saved
            if ($inserted !== FALSE) {
                //return the image name
                return "$img.$extension";
            }
        }
        //could not download the image
        return FALSE;
    }
}