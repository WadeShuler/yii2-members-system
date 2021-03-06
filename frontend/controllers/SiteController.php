<?php
namespace frontend\controllers;

use Yii;
use yii\base\InvalidArgumentException;
use yii\base\InvalidParamException;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\web\Controller;
use yii\web\BadRequestHttpException;

use common\models\User;

use frontend\models\ContactForm;
use frontend\models\LoginForm;
use frontend\models\PasswordResetRequestForm;
use frontend\models\ResendVerificationEmailForm;
use frontend\models\ResetPasswordForm;
use frontend\models\SignupForm;
use frontend\models\VerifyEmailForm;

/**
 * Site controller
 */
class SiteController extends Controller
{
    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'actions' => ['login', 'signup', 'request-password-reset', 'reset-password', 'verify-email', 'resend-verification-email', 'error'],
                        'allow' => true,
                    ],
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                    'delete' => ['post'],
                ],
            ],

        ];
    }

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
            'captcha' => [
                'class' => 'yii\captcha\CaptchaAction',
                'fixedVerifyCode' => YII_ENV_TEST ? 'testme' : null,
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return mixed
     */
    public function actionIndex()
    {
        return $this->render('index');
    }

    /**
     * Logs in a user.
     *
     * @return mixed
     */
    public function actionLogin()
    {
        if ( ! Yii::$app->user->isGuest ) {
            return $this->goHome();
        }

        $this->layout = '//no-sidebar';

        $model = new LoginForm();

        if ( $model->load(Yii::$app->request->post()) && $model->login() ) {
            return $this->goBack();
        }

        return $this->render('login', [
            'model' => $model,
        ]);
    }

    /**
     * Logs out the current user.
     *
     * @return mixed
     */
    public function actionLogout()
    {
        if (Yii::$app->user->logout()) {
            Yii::$app->session->setFlash('success', 'You have been logged out!');
        }

        return $this->goHome();
    }

    /**
     * Displays contact page.
     *
     * @return mixed
     */
    public function actionContact()
    {
        $model = new ContactForm();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail(Yii::$app->params['contactEmail'])) {
                Yii::$app->session->setFlash('success', 'Thank you for contacting us. We will respond to you as soon as possible.');
            } else {
                Yii::$app->session->setFlash('error', 'There was an error sending your message.');
            }

            return $this->refresh();
        }

        return $this->render('contact', [
            'model' => $model,
        ]);
    }

    /**
     * Signs user up.
     *
     * @todo Affiliate module here
     * @return mixed
     */
    public function actionSignup($aff = null)
    {
        $this->layout = '//no-sidebar';

        $sponsor = null;

        if ( $aff ) {
            // Sponsor forced in the URL
            $sponsor = User::findByUsername($aff);

            if ( $sponsor) {
                // sponsor passed by aff param was a valid account.
                // Handle like an affiliate link and set a cookie
                Yii::$app->affiliate->setCookie($sponsor->username);
            }
        }

        // aff param not passed, or it was for an invalid user
        // so see if we have a cookie to fall back on
        if ( ! $sponsor && ($cookie = Yii::$app->affiliate->getCookie())) {
            $sponsor = User::findByUsername($cookie);
        }

        // if we STILL don't have a sponsor, then assign one (if config allows)
        if ( ! $sponsor )
        {
            if ( Yii::$app->affiliate->randomizeOnSignupPage === true ) {
                // Pick a random user from the database to be their sponsor
                $sponsor = Yii::$app->affiliate->getRandomUser();
            } elseif ( Yii::$app->affiliate->fallbackOnSignupPage === true ) {
                if ( isset(Yii::$app->affiliate->fallbackSponsor) && ! empty(Yii::$app->affiliate->fallbackSponsor) && is_string(Yii::$app->affiliate->fallbackSponsor) ) {
                    // Find the fallback (default) sponsor
                    $sponsor = Yii::$app->affiliate->getFallbackSponsor();
                }
            }

            // if we assigned a sponsor then store the cookie (if config allows)
            if ( $sponsor && (Yii::$app->affiliate->storeCookieOnSignupPage === true) ) {
                Yii::$app->affiliate->setCookie($sponsor->username);
            }
        }

        $model = new SignupForm();

        if ($model->load(Yii::$app->request->post()) && $model->signup())
        {
            if ( Yii::$app->params['signupValidation'] === true ) {
                Yii::$app->session->setFlash('success', 'Your account has been created!<br>Before you can login, you must click the verification link in your email.');
            } else {
                Yii::$app->session->setFlash('success', 'Your account has been created!');
            }

            return $this->goHome();
        }

        return $this->render('signup', [
            'model' => $model,
            'sponsor' => $sponsor
        ]);
    }

    /**
     * Requests password reset.
     *
     * @return mixed
     */
    public function actionRequestPasswordReset()
    {
        $this->layout = '//no-sidebar';

        $model = new PasswordResetRequestForm();
        if ($model->load(Yii::$app->request->post()) && $model->validate())
        {
            if ($model->sendEmail())
            {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');

                return $this->goHome();
            } else {
                Yii::$app->session->setFlash('error', 'Sorry, we are unable to reset password for the provided email address.');
            }
        }

        return $this->render('requestPasswordResetToken', [
            'model' => $model,
        ]);
    }

    /**
     * Resets password.
     *
     * @param string $token
     * @return mixed
     * @throws BadRequestHttpException
     */
    public function actionResetPassword($token)
    {
        $this->layout = '//no-sidebar';

        try {
            $model = new ResetPasswordForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($model->load(Yii::$app->request->post()) && $model->validate() && $model->resetPassword()) {
            Yii::$app->session->setFlash('success', 'Your password has been reset!');

            return $this->goHome();
        }

        return $this->render('resetPassword', [
            'model' => $model,
        ]);
    }

    /**
     * Verify email address
     *
     * @param string $token
     * @throws BadRequestHttpException
     * @return yii\web\Response
     */
    public function actionVerifyEmail($token)
    {
        try {
            $model = new VerifyEmailForm($token);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        if ($user = $model->verifyEmail()) {
            if (Yii::$app->user->login($user)) {
                Yii::$app->session->setFlash('success', 'Your email has been confirmed!');
                return $this->goHome();
            }
        }

        Yii::$app->session->setFlash('error', 'Sorry, we are unable to verify your account with provided token.');
        return $this->goHome();
    }

    /**
     * Resend verification email
     *
     * @return mixed
     */
    public function actionResendVerificationEmail()
    {
        $this->layout = '//no-sidebar';

        $model = new ResendVerificationEmailForm();

        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            if ($model->sendEmail()) {
                Yii::$app->session->setFlash('success', 'Check your email for further instructions.');
                return $this->goHome();
            }

            Yii::$app->session->setFlash('error', 'Sorry, we are unable to resend a verification email for the provided email address.');
        }

        return $this->render('resendVerificationEmail', [
            'model' => $model
        ]);
    }
}
