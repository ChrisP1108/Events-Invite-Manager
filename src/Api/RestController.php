<?php

declare(strict_types=1);

namespace EventsInviteManager\Api;

if (!defined('ABSPATH')) exit;

use EventsInviteManager\Services\RsvpFlowResolver;

/**
 * Registers and handles the plugin's public-facing REST API endpoints.
 *
 * Route registration lives here; business logic is delegated to feature
 * controllers that extend AbstractApiController.
 *
 * Endpoints
 * ─────────
 *   GET  /wp-json/eim/v1/rsvp                → RsvpController::handleRsvp
 *   POST /wp-json/eim/v1/register             → RsvpController::handleRegister
 *   GET  /wp-json/eim/v1/dashboard            → DashboardController::handleDashboard
 *   GET  /wp-json/eim/v1/newsletters          → NewsletterController::handleNewsletters
 *   GET  /wp-json/eim/v1/registry             → RegistryController::handleRegistry
 *   POST /wp-json/eim/v1/registry/purchase    → RegistryController::handleRegistryPurchase
 *   POST /wp-json/eim/v1/request-guest        → GuestRequestController::handleRequestGuest
 *   GET  /wp-json/eim/v1/messages             → MessagesController::handleGetMessages
 *   POST /wp-json/eim/v1/messages             → MessagesController::handlePostMessage
 */
class RestController
{
    private const NAMESPACE = 'eim/v1';

    private RsvpController $rsvp;
    private DashboardController $dashboard;
    private NewsletterController $newsletter;
    private RegistryController $registry;
    private GuestRequestController $guestRequest;
    private MessagesController $messages;

    public function __construct(?RsvpFlowResolver $resolver = null)
    {
        $this->rsvp         = new RsvpController($resolver);
        $this->dashboard    = new DashboardController($resolver);
        $this->newsletter   = new NewsletterController($resolver);
        $this->registry     = new RegistryController($resolver);
        $this->guestRequest = new GuestRequestController($resolver);
        $this->messages     = new MessagesController($resolver);
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NAMESPACE, '/rsvp', [
                'methods'             => 'GET',
                'callback'            => [$this->rsvp, 'handleRsvp'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/newsletters', [
                'methods'             => 'GET',
                'callback'            => [$this->newsletter, 'handleNewsletters'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'newsletter_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/dashboard', [
                'methods'             => 'GET',
                'callback'            => [$this->dashboard, 'handleDashboard'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/registry', [
                'methods'             => 'GET',
                'callback'            => [$this->registry, 'handleRegistry'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'event_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/registry/purchase', [
                'methods'             => 'POST',
                'callback'            => [$this->registry, 'handleRegistryPurchase'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'event_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'gift_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'is_purchased' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/request-guest', [
                'methods'             => 'POST',
                'callback'            => [$this->guestRequest, 'handleRequestGuest'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'first_name'        => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'last_name'         => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'email'             => ['required' => true,  'type' => 'string',  'sanitize_callback' => 'sanitize_email'],
                    'phone'             => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'street_address'    => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'city'              => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'state'             => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'zip_code'          => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'notes'             => ['required' => false, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/messages', [
                'methods'             => 'GET',
                'callback'            => [$this->messages, 'handleGetMessages'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'event_id'          => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/messages', [
                'methods'             => 'POST',
                'callback'            => [$this->messages, 'handlePostMessage'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                    'event_id'          => ['required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                    'message'           => ['required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_textarea_field'],
                ],
            ]);

            register_rest_route(self::NAMESPACE, '/register', [
                'methods'             => 'POST',
                'callback'            => [$this->rsvp, 'handleRegister'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'confirmation_code' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'members' => [
                        'required' => false,
                        'type'     => 'array',
                        'items'    => [
                            'type'       => 'object',
                            'properties' => [
                                'invitee_id'          => ['type' => 'integer'],
                                'rsvp_status'         => ['type' => 'string', 'enum' => ['attending', 'declined', 'pending']],
                                'food_option_id'      => ['type' => 'integer'],
                                'beverage_option_id'  => ['type' => 'integer'],
                                'dietary_notes'       => ['type' => 'string'],
                                'lodging_id'          => ['type' => 'integer'],
                                'lodging_is_other'    => ['type' => 'boolean'],
                                'lodging_undisclosed' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                    'rsvp_notes' => [
                        'required' => false,
                        'type'     => 'string',
                    ],
                    'lodging_booked' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                    'lodging_notes' => [
                        'required' => false,
                        'type'     => 'string',
                    ],
                    'lodging_id' => [
                        'required' => false,
                        'type'     => 'integer',
                    ],
                    'lodging_is_other' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                    'lodging_undisclosed' => [
                        'required' => false,
                        'type'     => 'boolean',
                    ],
                ],
            ]);
        });
    }
}
