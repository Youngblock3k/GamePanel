<?php
declare(strict_types=1);

/** @var App\Router $router */
$A = ['auth'];
$AD = ['admin'];

// Authentication
$router->add('GET', '/login', 'AuthController@loginForm', ['guest']);
$router->add('POST', '/login', 'AuthController@login', ['guest']);
$router->add('GET', '/login/2fa', 'AuthController@twoFaForm', ['guest']);
$router->add('POST', '/login/2fa', 'AuthController@twoFa', ['guest']);
$router->add('GET', '/forgot', 'PasswordController@forgotForm', ['guest']);
$router->add('POST', '/forgot', 'PasswordController@forgot', ['guest']);
$router->add('GET', '/reset/{token}', 'PasswordController@resetForm', ['guest']);
$router->add('POST', '/reset/{token}', 'PasswordController@reset', ['guest']);
$router->add('POST', '/logout', 'AuthController@logout', $A);

// Dashboard
$router->add('GET', '/', 'ServerController@index', $A);

// Server pages
$router->add('GET', '/server/{uuid}', 'ServerController@console', $A);
$router->add('GET', '/server/{uuid}/state', 'ServerController@state', $A);
$router->add('GET', '/server/{uuid}/logs', 'ServerController@logs', $A);
$router->add('POST', '/server/{uuid}/power', 'ServerController@power', $A);
$router->add('POST', '/server/{uuid}/command', 'ServerController@command', $A);
$router->add('GET', '/server/{uuid}/files', 'FileController@page', $A);
$router->add('GET', '/server/{uuid}/files/list', 'FileController@list', $A);
$router->add('GET', '/server/{uuid}/files/read', 'FileController@read', $A);
$router->add('POST', '/server/{uuid}/files/write', 'FileController@write', $A);
$router->add('POST', '/server/{uuid}/files/delete', 'FileController@delete', $A);
$router->add('POST', '/server/{uuid}/files/pull', 'FileController@pull', $A);
$router->add('POST', '/server/{uuid}/files/mkdir', 'FileController@mkdir', $A);
$router->add('POST', '/server/{uuid}/files/rename', 'FileController@rename', $A);
$router->add('GET', '/server/{uuid}/settings', 'ServerController@settings', $A);
$router->add('POST', '/server/{uuid}/settings', 'ServerController@settingsSave', $A);
$router->add('GET', '/server/{uuid}/activity', 'ServerController@activity', $A);
$router->add('GET', '/server/{uuid}/plugins', 'PluginController@page', $A);
$router->add('GET', '/server/{uuid}/plugins/search', 'PluginController@search', $A);
$router->add('POST', '/server/{uuid}/plugins/install', 'PluginController@install', $A);
$router->add('GET', '/server/{uuid}/subusers', 'SubuserController@page', $A);
$router->add('POST', '/server/{uuid}/subusers', 'SubuserController@add', $A);
$router->add('POST', '/server/{uuid}/subusers/{id}/remove', 'SubuserController@remove', $A);

// Brand logo (any signed-in user; it is shown in the sidebar)
$router->add('GET', '/theme.css', 'BrandingController@themeCss', []);
$router->add('GET', '/brand/logo', 'BrandingController@logo', []);

// Account + security
$router->add('GET', '/account', 'AccountController@index', $A);
$router->add('POST', '/account/password', 'AccountController@password', $A);
$router->add('GET', '/account/security', 'AccountController@security', $A);
$router->add('POST', '/account/2fa/start', 'AccountController@twoFaStart', $A);
$router->add('POST', '/account/2fa/enable', 'AccountController@twoFaEnable', $A);
$router->add('POST', '/account/2fa/disable', 'AccountController@twoFaDisable', $A);
$router->add('POST', '/account/2fa/recovery', 'AccountController@recoveryRegen', $A);
$router->add('POST', '/account/sessions/revoke', 'AccountController@revokeSessions', $A);
$router->add('POST', '/account/avatar', 'AccountController@avatarSave', $A);
$router->add('POST', '/account/theme', 'AccountController@themeSave', $A);
$router->add('GET', '/avatar/{name}', 'AccountController@avatarShow', $A);
$router->add('GET', '/account/activity', 'AccountController@activity', $A);
$router->add('GET', '/account/api', 'AccountController@apiKeys', $A);
$router->add('POST', '/account/api', 'AccountController@apiCreate', $A);
$router->add('POST', '/account/api/delete', 'AccountController@apiDelete', $A);

// Admin
$router->add('GET', '/admin', 'AdminController@overview', $AD);
$router->add('GET', '/admin/overview', 'AdminController@overviewPage', $AD);
$router->add('GET', '/admin/users/new', 'AdminController@userNew', $AD);
$router->add('GET', '/admin/users', 'AdminController@users', $AD);
$router->add('GET', '/admin/roles/new', 'RoleController@form', $AD);
$router->add('GET', '/admin/roles', 'RoleController@index', $AD);
$router->add('POST', '/admin/roles', 'RoleController@create', $AD);
$router->add('GET', '/admin/roles/{id}', 'RoleController@form', $AD);
$router->add('POST', '/admin/roles/{id}', 'RoleController@update', $AD);
$router->add('POST', '/admin/roles/{id}/delete', 'RoleController@delete', $AD);
$router->add('GET', '/admin/theme', 'AccountController@themePage', $AD);
$router->add('GET', '/account/theme', 'AccountController@themePage', $A);
$router->add('POST', '/admin/users', 'AdminController@userCreate', $AD);
$router->add('POST', '/admin/users/action', 'AdminController@userAction', $AD);
$router->add('GET', '/admin/locations/new', 'AdminController@locationNew', $AD);
$router->add('GET', '/admin/locations', 'AdminController@locations', $AD);
$router->add('POST', '/admin/locations', 'AdminController@locationCreate', $AD);
$router->add('POST', '/admin/locations/delete', 'AdminController@locationDelete', $AD);
$router->add('GET', '/admin/eggs', 'EggController@index', $AD);
$router->add('POST', '/admin/eggs', 'EggController@create', $AD);
$router->add('POST', '/admin/eggs/import', 'EggController@import', $AD);
$router->add('POST', '/admin/eggs/preset', 'EggController@preset', $AD);
$router->add('GET', '/admin/eggs/{id}', 'EggController@edit', $AD);
$router->add('POST', '/admin/eggs/{id}', 'EggController@update', $AD);
$router->add('GET', '/admin/eggs/{id}/export', 'EggController@export', $AD);
$router->add('POST', '/admin/eggs/{id}/clone', 'EggController@clone', $AD);
$router->add('POST', '/admin/eggs/{id}/delete', 'EggController@delete', $AD);
$router->add('GET', '/admin/settings', 'SettingsController@page', $AD);
$router->add('POST', '/admin/settings', 'SettingsController@save', $AD);
$router->add('GET', '/admin/branding', 'BrandingController@page', $AD);
$router->add('POST', '/admin/branding', 'BrandingController@save', $AD);
$router->add('GET', '/admin/audit', 'AdminController@audit', $AD);
$router->add('GET', '/admin/monitoring', 'MonitoringController@page', $AD);
$router->add('GET', '/admin/databases/new', 'DatabaseController@hostNew', $AD);
$router->add('GET', '/admin/databases', 'DatabaseController@hosts', $AD);
$router->add('POST', '/admin/databases/hosts', 'DatabaseController@hostCreate', $AD);
$router->add('POST', '/admin/databases/hosts/{id}/delete', 'DatabaseController@hostDelete', $AD);
$router->add('POST', '/admin/servers/{id}/databases', 'DatabaseController@create', $AD);
$router->add('POST', '/admin/servers/{id}/databases/{db}/rotate', 'DatabaseController@rotate', $AD);
$router->add('POST', '/admin/servers/{id}/databases/{db}/delete', 'DatabaseController@delete', $AD);
$router->add('GET', '/admin/nodes/new', 'AdminInfraController@nodeNew', $AD);
$router->add('GET', '/admin/nodes', 'AdminInfraController@nodes', $AD);
$router->add('POST', '/admin/nodes', 'AdminInfraController@nodeCreate', $AD);
$router->add('GET', '/admin/nodes/{id}', 'AdminInfraController@nodeShow', $AD);
$router->add('POST', '/admin/nodes/{id}/allocations', 'AdminInfraController@allocationsAdd', $AD);
$router->add('POST', '/admin/nodes/{id}/maintenance', 'AdminInfraController@nodeMaintenance', $AD);
$router->add('POST', '/admin/nodes/local', 'AdminInfraController@nodeLocal', $AD);
$router->add('POST', '/admin/nodes/{id}/update', 'AdminInfraController@nodeUpdate', $AD);
$router->add('POST', '/admin/nodes/{id}/rotate', 'AdminInfraController@nodeRotate', $AD);
$router->add('POST', '/admin/nodes/{id}/allocations/{aid}/delete', 'AdminInfraController@allocationDelete', $AD);
$router->add('POST', '/admin/nodes/{id}/delete', 'AdminInfraController@nodeDelete', $AD);
$router->add('GET', '/admin/servers', 'AdminInfraController@servers', $AD);
$router->add('GET', '/admin/servers/new', 'AdminInfraController@serverNew', $AD);
$router->add('GET', '/admin/servers/check-version', 'AdminInfraController@checkVersion', $AD);
$router->add('POST', '/admin/servers', 'AdminInfraController@serverCreate', $AD);
$router->add('GET', '/admin/servers/{id}', 'DatabaseController@serverPage', $AD);
$router->add('POST', '/admin/servers/action', 'AdminInfraController@serverAction', $AD);

// Application API (Bearer token, no CSRF, no cookies)
$router->add('GET', '/api/v1/servers', 'ApiController@servers', ['api']);
$router->add('GET', '/api/v1/servers/{uuid}', 'ApiController@show', ['api']);
$router->add('POST', '/api/v1/servers/{uuid}/power', 'ApiController@power', ['api']);
