<?php

namespace Objects\APIBundle\Controller;

require_once __DIR__ . '/../../../../vendor/FacebookApiLibrary/src/facebook.php';

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;
use Facebook;

/**
 * @author mirehan
 */
class FacebookController extends Controller {

    public function facebookLoginAction($size, $text) {
        $url = $this->generateUrl("Facebook_login_check");
        return $this->render('ObjectsAPIBundle:Facebook:facebookLoginBtn.html.twig', array('fb_app_id' => $this->container->getParameter('fb_app_id'), 'fb_app_secret' => $this->container->getParameter('fb_app_secret'),
                    'url' => $url, 'size' => $size, 'text' => $text));
    }

    public function facebookAction() {
        $request = $this->getRequest();
        $facebook = new \Facebook(array(
                    'appId' => $this->container->getParameter('fb_app_id'),
                    'secret' => $this->container->getParameter('fb_app_secret'),
                ));
        $fb_user_id = $facebook->getUser();
        $fb_access_token = $facebook->getAccessToken();
        //get the admin pages
        $pages = $this->adminUserPagesAccess($fb_user_id, $fb_access_token);
        //decode the data
        $pagesData = json_decode($pages);
        //get the user required page from the configuration file
        $userPage = $this->container->getParameter('fb_page_name');
        //initialize the page found flag
        $found = FALSE;
        //try to find the user required page
        foreach ($pagesData->data as $page) {
            //check if this page is the user requested page
            if ($page->name == $userPage) {
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
                    $value['parameters']['fb_access_token'] = $fb_access_token;
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
                        //redirect the user to configuration page to show the flag
                        return $this->redirect($this->generateUrl('config', array(), TRUE));
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
            $message = 'the page requested is not correct please go to the <a href="' . $this->generateUrl('config') . '">configurations page</a> and edit fb page name';
        }
        return $this->render('::general_admin.html.twig', array(
                    'message' => $message
                ));
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

    public function tokenAction($route) {
        $request = $this->getRequest();

        $returnURL = $request->query->get('returnURL');
        $my_url = $this->generateUrl('Facebook_token', array('route' => $route, 'returnURL' => $returnURL), true);
        $code = $request->query->get('code');
        $error = $request->query->get('error_reason');
        $session = $request->getSession();
        $session->set('facebook_returnURL', $returnURL);

        if (isset($error)) {

            $session->setFlash('facebook_error', $error);
            return $this->redirect($this->generateUrl('Facebook_Data', array('route' => $route)));
        } else {
            if ($request->query->get('state') == FACEBOOK_APP_STATE) {
                $token_url = 'https://graph.facebook.com/oauth/access_token?'
                        . 'client_id=' . FACEBOOK_APP_ID . '&redirect_uri=' . urlencode($my_url)
                        . '&client_secret=' . FACEBOOK_APP_SECRET . '&code=' . $code;

                $response = @file_get_contents($token_url);
                $params = null;
                parse_str($response, $params);
                if ($params['access_token']) {
                    $session->setFlash('facebook_access_token', $params['access_token']);
                } else {
                    $session->setFlash('facebook_error', 'Invalid access token');
                }

                return $this->redirect($this->generateUrl('Facebook_Data', array('route' => $route)));
            } else {
                $session->setFlash('facebook_error', MISMATCH_FACEBOOK_STATE);
                return $this->redirect($this->generateUrl('Facebook_Data', array('route' => $route)));
            }
        }
    }

    public function getFacebookUserAction($route) {
        $request = $this->getRequest();

        $session = $request->getSession();
        //retrive from the session access token
        $accessToken = $session->getFlash('facebook_access_token');
        //retrive from the session facebook error
        $facebookError = $session->getFlash('facebook_error');

        if ($facebookError) {
            $session->setFlash('facebook_error', $facebookError);
            return $this->redirect($this->generateUrl($route));
        }
        if ($accessToken) {

            $graph_url = 'https://graph.facebook.com/me?access_token=' . $accessToken;

            $faceUser = json_decode(file_get_contents($graph_url));
            $session->setFlashes(array('facebook_access_token' => $accessToken, 'facebook_user' => $faceUser));
            return $this->redirect($this->generateUrl($route));
        }
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
    public static function postOnUserWallAndFeedAction($accessToken, $message, $name, $link, $picture) {

        $fieldsString = "access_token=$accessToken&message=$message&name=$name&picture=$picture";
        $fieldsString.="&link=$link&description=" . FACEBOOK_APP_DESCRIPTION;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/me/feed");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);
        curl_exec($ch);
        curl_close($ch);
        return new Response('postOnUserWallAndFeed');
    }

}