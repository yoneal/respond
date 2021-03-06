<?php

namespace App\Http\Controllers;

use App\Respond\Models\Site;
use App\Respond\Models\User;
use App\Respond\Models\Page;

use \Illuminate\Http\Request;

use App\Respond\Libraries\Utilities;
use App\Respond\Libraries\Publish;

class SiteController extends Controller
{

  /**
  * Retrieve the user for the given ID.
  *
  * @return Response
  */
  public function test()
  {

    return '[Respond] API works!';

  }

  /**
   * Creates the site
   *
   * @return Response
   */
  public function create(Request $request)
  {

    // get request
    $name = $request->json()->get('name');
    $theme = $request->json()->get('theme');
    $email = $request->json()->get('email');
    $password = $request->json()->get('password');
    $passcode = $request->json()->get('passcode');
    $gRecaptchaResponse = $request->json()->get('recaptchaResponse');

    // handle reCAPTCHA
    if(isset($gRecaptchaResponse)) {

      $secret = env('RECAPTCHA_SECRET_KEY');

      // check if secret is set
      if(isset($secret)) {

        // do not check if secret is empty
        if($secret != '') {

          $recaptcha = new \ReCaptcha\ReCaptcha($secret);
          $remoteIp = $request->ip();

          $resp = $recaptcha->verify($gRecaptchaResponse, $remoteIp);

          if ($resp->isSuccess()) {
            // verified! continue
          } else {
              $errors = $resp->getErrorCodes();
              return response('reCAPTCHA invalid', 401);
          }

        }

      }
      else {
        return response('reCAPTCHA error no secret', 401);
      }

    }

    if($passcode == env('PASSCODE')) {

      $arr = Site::create($name, $theme, $email, $password);

      return response()->json($arr);
    }
    else {
      return response('Passcode invalid', 401);
    }

  }

  /**
   * Activates the site
   *
   * @return Response
   */
  public function active(Request $request)
  {

    // get request
    $siteId = $request->input('id');
    $key = $request->input('key');

    if($key == env('APP_KEY')) {

      $site = Site::getById($siteId);
      $site->activate();

      return response('Ok', 200);
    }
    else {
      return response('Passcode invalid', 401);
    }

  }

  /**
   * Subscribes a user via Stripe
   *
   * @return Response
   */
  public function subscribe(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get token
    $stripeToken = $request->json()->get('token');
    $stripeEmail = $request->json()->get('email');

    // get site
    $site = Site::getById($siteId);

    try {

      // #ref https://stripe.com/docs/recipes/subscription-signup
      \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));

      $customer = \Stripe\Customer::create(array(
        'email' => $stripeEmail,
        'source'  => $stripeToken,
        'plan' => env('STRIPE_PLAN')
      ));

      // activate
      $site->status = 'Active';
      $site->customerId = $customer->id;
      $site->save();

      return response('Ok', 200);
    }
    catch(Exception $e)
    {
      return response('Unable to subscribe', 401);
    }


  }

  /**
   * Reloads system files for sites (e.g. plugins)
   *
   * @return Response
   */
  public function reload(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get site
    $site = Site::getById($siteId);

    // get user
    $user = User::getByEmail($email, $siteId);

    // publish plugins
    Publish::publishPlugins($user, $site);

    return response('Ok', 200);

  }

  /**
   * Migrates a R5 site to R6
   *
   * @return Response
   */
  public function migrate(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get site
    $site = Site::getById($siteId);

    // get user
    $user = User::getByEmail($email, $siteId);

    // migrate site
    Publish::migrate($user, $site);

    return response('Ok', 200);

  }

  /**
   * Republishes templates and pushed the change to pages that inherit from it
   *
   * @return Response
   */
  public function republishTemplates(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get site
    $site = Site::getById($siteId);

    // get user
    $user = User::getByEmail($email, $siteId);

    // migrate site
    Publish::publishTemplates($user, $site);

    // re-publish plugins
    Publish::publishPlugins($user, $site);

    // re-publish site map
    Publish::publishSiteMap($user, $site);

    // re-publish the settings
    Publish::publishSettings($user, $site);

    return response('Ok', 200);

  }

  /**
   * Republishes templates and pushed the change to pages that inherit from it
   *
   * @return Response
   */
  public function updatePlugins(Request $request)
  {
    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get site
    $site = Site::getById($siteId);

    // get user
    $user = User::getByEmail($email, $siteId);

    if($site->theme == NULL || $site->theme == '') {
      return response('Theme not set.', 400);
    }

    // update plugins
    Publish::updatePlugins($site);

    // re-publish plugins
    Publish::publishPlugins($user, $site);

    return response('Ok', 200);

  }

  /**
   * Generates a sitemap.xml for the site
   *
   * @return Response
   */
  public function generateSitemap(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get site
    $site = Site::getById($siteId);

    // get user
    $user = User::getByEmail($email, $siteId);

    // publish site map
    Publish::publishSiteMap($user, $site);

    return response('Ok', 200);

  }

  /**
   * Re-index pages (updates JSON, republishes sitemap)
   *
   * @return Response
   */
  public function reindexPages(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $siteId = $request->input('auth-id');

    // get site
    $site = Site::getById($siteId);

    // get user
    $user = User::getByEmail($email, $siteId);

    // refresh JSON
    Page::refreshJSON($user, $site);

    // publish site map
    Publish::publishSiteMap($user, $site);

    return response('Ok', 200);

  }

  /**
   * Lists the templates for a given site
   *
   * @return Response
   */
  public function listTemplates(Request $request)
  {

    // get request data
    $email = $request->input('auth-email');
    $id = $request->input('auth-id');

    $site = Site::getById($id);

    // set dir
    $dir = app()->basePath().'/public/sites/'.$site->id.'/templates';

    // list files
    $files = Utilities::ListFiles($dir, $site->id,
            array('html'),
            array());


    // get template
    foreach($files as &$file) {

      $file = basename($file);
      $file = preg_replace('/\\.[^.\\s]{3,4}$/', '', $file);

    }


    return response()->json($files);

  }


}