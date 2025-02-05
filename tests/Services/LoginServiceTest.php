<?php

namespace SimpleJwtLoginTests\Services;

use Exception;
use PHPUnit\Framework\TestCase;
use SimpleJWTLogin\ErrorCodes;
use SimpleJWTLogin\Helpers\ServerHelper;
use SimpleJWTLogin\Libraries\JWT;
use SimpleJWTLogin\Modules\Settings\LoginSettings;
use SimpleJWTLogin\Modules\SimpleJWTLoginHooks;
use SimpleJWTLogin\Modules\SimpleJWTLoginSettings;
use SimpleJWTLogin\Modules\WordPressDataInterface;
use SimpleJWTLogin\Services\LoginService;

class LoginServiceTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|WordPressDataInterface
     */
    private $wordPressDataMock;

    public function setUp(): void
    {
        parent::setUp();
        $this->wordPressDataMock = $this
            ->getMockBuilder(WordPressDataInterface::class)
            ->getMock();
    }

    /**
     * @dataProvider validationProvider
     * @param array $settings
     * @param array $request
     * @param string $exceptionMessage
     * @throws Exception
     */
    public function testValidation($settings, $request, $exceptionMessage)
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($exceptionMessage);

        $this->wordPressDataMock->method('getOptionFromDatabase')
            ->willReturn(json_encode($settings));

        $service = (new LoginService())
            ->withRequest($request)
            ->withCookies([])
            ->withServerHelper(new ServerHelper([
                'HTTP_CLIENT_IP' => '127.0.0.1'
            ]))
            ->withSession([])
            ->withSettings(new SimpleJWTLoginSettings($this->wordPressDataMock));

        $service->makeAction();
    }

    public function validationProvider()
    {
        return [
            'empty_settings_and_request' => [
                'settings' => [],
                'request' => [],
                'exceptionMessage' => 'Auto-login is not enabled on this website.'
            ],
            'autologin_disabled' => [
                'settings' => [
                    'allow_autologin' => false,
                ],
                'request' => [],
                'exceptionMessage' => 'Auto-login is not enabled on this website.'
            ],
            'empty_request_and_autologin_enabled' => [
                'settings' => [
                    'allow_autologin' => 'true',
                ],
                'request' => [],
                'exceptionMessage' => 'Wrong Request.',
            ],
            'missing_auth_code' => [
                'settings' => [
                    'allow_autologin' => true,
                    'require_login_auth' => true,
                ],
                'request' => [
                    'JWT'  => 'test',
                ],
                'exceptionMessage' => 'Invalid Auth Code ( AUTH_KEY ) provided.',
            ],
            'invalid_auth_code' => [
                'settings' => [
                    'allow_autologin' => true,
                    'require_login_auth' => true,
                    'auth_codes' => [
                        [
                            'code' => 'some-key',
                            'role' => '',
                            'expiration_date' => '',
                        ],
                    ],
                ],
                'request' => [
                    'JWT' => 'test',
                    'AUTH_KEY' => 'test'
                ],
                'exception' => 'Invalid Auth Code ( AUTH_KEY ) provided.',
            ],
            'ip_not_allowed' => [
                'settings' => [
                    'allow_autologin' => true,
                    'require_login_auth' => false,
                    'login_ip' => '127.2.2.2,127.02.02.02, 127.0.0.0',
                ],
                'request' => [
                    'JWT' => 'test',
                ],
                'exception' => 'This IP[ 127.0.0.1 ] is not allowed to auto-login.',
            ],
            'test_unable_to_find_user_in_jwt' => [
                'settings' => [
                    'allow_autologin' => true,
                    'require_login_auth' => false,
                    'decryption_key' => 'test',
                    'jwt_login_by_parameter' => 'test'
                ],
                'request' => [
                    'JWT' => JWT::encode(
                        ['id' => 1],
                        'test',
                        'HS256'
                    )
                ],
                'exception' => 'Unable to find user test property in JWT.',
            ],
            'test_unable_to_find_user_in_jwt_nested' => [
                'settings' => [
                    'allow_autologin' => true,
                    'require_login_auth' => false,
                    'decryption_key' => 'test',
                    'jwt_login_by_parameter' => 'user.properties'
                ],
                'request' => [
                    'JWT' => JWT::encode(
                        [
                            'user' => [
                                'someKey' => [
                                    'id' => 1
                                ]
                            ],
                        ],
                        'test',
                        'HS256'
                    )
                ],
                'exception' => 'Unable to find user properties property in JWT.',
            ]
        ];
    }

    public function testUserNotFound()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('User not found');
        $this->expectExceptionCode(ErrorCodes::ERR_DO_LOGIN_USER_NOT_FOUND);

        $this->wordPressDataMock->method('getOptionFromDatabase')
            ->willReturn(json_encode([
                'allow_autologin' => true,
                'require_login_auth' => false,
                'decryption_key' => 'test',
                'jwt_login_by_parameter' => 'id',
            ]));

        $service = (new LoginService())
            ->withRequest([
                'JWT' => JWT::encode(
                    ['id' => 1],
                    'test',
                    'HS256'
                )
            ])
            ->withCookies([])
            ->withServerHelper(new ServerHelper([
                'HTTP_CLIENT_IP' => '127.0.0.1'
            ]))
            ->withSession([])
            ->withSettings(new SimpleJWTLoginSettings($this->wordPressDataMock));

        $service->makeAction();
    }

    public function testLoginWithRevokedJWT()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This JWT is invalid.');
        $this->expectExceptionCode(ErrorCodes::ERR_REVOKED_TOKEN);

        $jwt = JWT::encode(
            ['id' => 1],
            'test',
            'HS256'
        );
        $this->wordPressDataMock->method('getOptionFromDatabase')
            ->willReturn(json_encode([
                'allow_autologin' => true,
                'require_login_auth' => false,
                'decryption_key' => 'test',
                'jwt_login_by_parameter' => 'id',
                'jwt_login_by' => LoginSettings::JWT_LOGIN_BY_EMAIL
            ]));
        $this->wordPressDataMock->method('getUserDetailsByEmail')
            ->willReturn(true);
        $this->wordPressDataMock->method('isInstanceOfuser')
            ->willReturn(true);
        $this->wordPressDataMock->method('getUserProperty')
            ->willReturn(1);
        $this->wordPressDataMock->method('getUserMeta')
            ->willReturn([
                $jwt,
            ]);
        $service = (new LoginService())
            ->withRequest([
                'JWT' => $jwt
            ])
            ->withCookies([])
            ->withServerHelper(new ServerHelper([
                'HTTP_CLIENT_IP' => '127.0.0.1'
            ]))
            ->withSession([])
            ->withSettings(new SimpleJWTLoginSettings($this->wordPressDataMock));

        $service->makeAction();
    }

    /**
     * @dataProvider loginProvider
     * @param array|null $request
     * @param array|null $session
     * @param array|null $cookie
     * @param array|null $headers
     * @throws Exception
     */
    public function testSuccess($loginBy, $request, $session, $cookie, $headers)
    {
        $this->wordPressDataMock->method('getOptionFromDatabase')
            ->willReturn(json_encode([
                'allow_autologin' => true,
                'require_login_auth' => false,
                'decryption_key' => 'test',
                'jwt_login_by_parameter' => 'user.id',
                'jwt_login_by' => $loginBy,
                'request_jwt_session' => true,
                'request_jwt_header' => true,
                'request_jwt_cookie' => true,
                'request_jwt_url' => true,
                'enabled_hooks' => [
                    SimpleJWTLoginHooks::LOGIN_ACTION_NAME
                ],
            ]));
        $this->wordPressDataMock->method('getUserMeta')
            ->willReturn([
                Jwt::encode(['test' => 1], 'test', 'HS256'), //another JWT
            ]);
        $this->wordPressDataMock->method('getUserDetailsByEmail')
            ->willReturn(true);
        $this->wordPressDataMock->method('getUserByUserLogin')
            ->willReturn(true);
        $this->wordPressDataMock->method('getUserDetailsById')
            ->willReturn(true);
        $this->wordPressDataMock->method('isInstanceOfuser')
            ->willReturn(true);
        $this->wordPressDataMock->method('getUserProperty')
            ->willReturn(1);
        $this->wordPressDataMock->method('getUserMeta')
            ->willReturn([]);
        $this->wordPressDataMock->method('loginUser')
            ->willReturn(true);

        $service = (new LoginService())
            ->withRequest($request)
            ->withCookies($cookie)
            ->withServerHelper(new ServerHelper($headers))
            ->withSession($session)
            ->withSettings(new SimpleJWTLoginSettings($this->wordPressDataMock));

        $result = $service->makeAction();
        $this->assertNull($result);
    }

    public function loginProvider()
    {
        $jwt = JWT::encode(
            [
                'user' => ['id' => 1]
            ],
            'test',
            'HS256'
        );

        return [
           'test_jwt_in_request' => [
               'loginBy' => LoginSettings::JWT_LOGIN_BY_EMAIL,
                'request' => [
                    'JWT' => $jwt,
                ],
                'session' => [],
                'cookie' => [],
                'headers' => [],
           ],
            'test_jwt_in_session' =>  [
                'loginBy' => LoginSettings::JWT_LOGIN_BY_EMAIL,
                'request' => [],
                'session' => [
                    'simple-jwt-login-token' => $jwt,
                ],
                'cookie' => [],
                'headers' => [],
            ],
            'test_jwt_in_cookie' => [
                'loginBy' => LoginSettings::JWT_LOGIN_BY_EMAIL,
                'request' => [],
                'session' => [],
                'cookie' => [
                    'simple-jwt-login-token' => $jwt
                ],
                'headers' => [],
            ],
            'test_jwt_in_header' => [
                'loginBy' => LoginSettings::JWT_LOGIN_BY_USER_LOGIN,
                'request' => [],
                'session' => [],
                'cookie' => [],
                'headers' => [
                    'HTTP_Authorization' => $jwt
                ],
            ],
            'test_jwt_in_header_with_bearer' => [
                'loginBy' => LoginSettings::JWT_LOGIN_BY_WORDPRESS_USER_ID,
                'request' => [],
                'session' => [],
                'cookie' => [],
                'headers' => [
                    'HTTP_Authorization' => 'Bearer ' . $jwt
                ],
            ],

        ];
    }
}
