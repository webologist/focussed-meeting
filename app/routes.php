<?php
/** @var App\Core\Router $router */

// Public
$router->get('/', 'PublicController@home');
$router->get('/register', 'AuthController@showRegister', ['guest']);
$router->post('/register', 'AuthController@register', ['guest']);
$router->get('/login', 'AuthController@showLogin', ['guest']);
$router->post('/login', 'AuthController@login', ['guest']);
$router->post('/logout', 'AuthController@logout');
$router->get('/forgot-password', 'AuthController@showForgot', ['guest']);
$router->post('/forgot-password', 'AuthController@forgot', ['guest']);
$router->get('/reset-password', 'AuthController@showReset');
$router->post('/reset-password', 'AuthController@reset');
$router->get('/verify-email', 'AuthController@verifyNotice', ['auth-unverified']);
$router->post('/verify-email/resend', 'AuthController@resendVerification', ['auth-unverified']);
$router->get('/verify', 'AuthController@verify');
$router->get('/accept-invite', 'AuthController@showAcceptInvite');
$router->post('/accept-invite', 'AuthController@acceptInvite');

// Dashboard, to-dos, reminders
$router->get('/dashboard', 'DashboardController@index', ['auth']);
$router->post('/tasks', 'ActionController@storeTask', ['auth']);
$router->post('/reminders', 'ReminderController@store', ['auth']);
$router->post('/reminders/{id}/delete', 'ReminderController@destroy', ['auth']);
$router->get('/notifications', 'NotificationController@index', ['auth']);
$router->post('/notifications/read', 'NotificationController@markRead', ['auth']);

// Action tracker and items
$router->get('/tracker', 'ActionController@tracker', ['auth']);
$router->get('/actions/{id}', 'ActionController@show', ['auth']);
$router->post('/actions/{id}/toggle', 'ActionController@toggle', ['auth']);
$router->post('/actions/{id}/deadline', 'ActionController@deadline', ['auth']);
$router->post('/actions/{id}/comment', 'ActionController@comment', ['auth']);
$router->post('/actions/{id}/note', 'ActionController@note', ['auth']);
$router->post('/actions/{id}/notes/{note}/delete', 'ActionController@deleteNote', ['auth']);
$router->post('/actions/{id}/dependency', 'ActionController@addDependency', ['auth']);
$router->post('/actions/{id}/dependency/{dep}/delete', 'ActionController@removeDependency', ['auth']);
$router->post('/actions/{id}/nudge', 'ActionController@nudge', ['auth']);
$router->post('/actions/nudge-overdue', 'ActionController@nudgeOverdue', ['auth']);
$router->post('/actions/{id}/delete', 'ActionController@destroy', ['auth']);
$router->get('/images/{id}', 'ActionController@image', ['auth']);

// Meetings (list + planner on one page)
$router->get('/meetings', 'MeetingController@index', ['auth']);
$router->post('/meetings', 'MeetingController@store', ['auth', 'admin']);
$router->get('/meetings/{id}', 'MeetingController@show', ['auth']);
$router->post('/meetings/{id}/complete', 'MeetingController@complete', ['auth']);
$router->post('/meetings/{id}/cancel', 'MeetingController@cancel', ['auth']);
$router->post('/meetings/{id}/resend', 'MeetingController@resend', ['auth']);
$router->post('/meetings/{id}/minutes', 'MeetingController@saveMinutes', ['auth']);
$router->post('/meetings/{id}/points', 'MeetingController@addPoint', ['auth']);
$router->post('/meetings/{id}/actions', 'MeetingController@addAction', ['auth']);
$router->post('/meetings/{id}/rsvp', 'MeetingController@rsvp', ['auth']);
$router->get('/meetings/{id}/mom', 'MeetingController@mom', ['auth']);
$router->post('/meetings/{id}/mom/send', 'MeetingController@sendMom', ['auth']);
$router->get('/meetings/{id}/print/invite', 'MeetingController@printInvite', ['auth']);
$router->get('/meetings/{id}/print/mom', 'MeetingController@printMom', ['auth']);
$router->get('/meetings/{id}/ics', 'MeetingController@ics', ['auth']);

// Reports (admin)
$router->get('/reports', 'ReportController@index', ['auth', 'admin']);
$router->get('/reports/export', 'ReportController@export', ['auth', 'admin']);

// Account & team
$router->get('/account', 'AccountController@index', ['auth']);
$router->post('/account/profile', 'AccountController@saveProfile', ['auth']);
$router->post('/account/preferences', 'AccountController@savePreferences', ['auth']);
$router->post('/account/password', 'AccountController@changePassword', ['auth']);
$router->get('/team', 'TeamController@index', ['auth']);
$router->post('/team/invite', 'TeamController@invite', ['auth', 'admin']);
$router->post('/team/{id}/role', 'TeamController@role', ['auth', 'admin']);
$router->post('/team/{id}/status', 'TeamController@status', ['auth', 'admin']);
$router->post('/team/{id}/resend', 'TeamController@resend', ['auth', 'admin']);
$router->post('/team/company', 'TeamController@company', ['auth', 'admin']);

// Integrations (admin)
$router->get('/integrations', 'IntegrationController@index', ['auth', 'admin']);
$router->post('/integrations/smtp', 'IntegrationController@saveSmtp', ['auth', 'admin']);
$router->post('/integrations/smtp/test', 'IntegrationController@testSmtp', ['auth', 'admin']);
$router->post('/integrations/whatsapp', 'IntegrationController@saveWhatsApp', ['auth', 'admin']);
$router->post('/integrations/{provider}/disconnect', 'IntegrationController@disconnect', ['auth', 'admin']);
$router->get('/integrations/google/connect', 'IntegrationController@googleConnect', ['auth', 'admin']);
$router->get('/integrations/google/callback', 'IntegrationController@googleCallback', ['auth', 'admin']);
$router->get('/integrations/microsoft/connect', 'IntegrationController@microsoftConnect', ['auth', 'admin']);
$router->get('/integrations/microsoft/callback', 'IntegrationController@microsoftCallback', ['auth', 'admin']);
