<?php

declare(strict_types=1);

/**
 * A token may be bound to a role (ADR 0011): its capabilities are then the role's
 * *current* capabilities, resolved live at authentication — so tightening a role
 * immediately tightens every token minted from it (central control). ON DELETE
 * SET NULL: deleting a role does not delete its tokens; the binding clears and
 * the token falls back to its explicit abilities (empty → deny-by-default).
 */
return [
    'ALTER TABLE nb_api_tokens ADD COLUMN role_id INT UNSIGNED NULL AFTER abilities',
    'ALTER TABLE nb_api_tokens ADD CONSTRAINT fk_token_role FOREIGN KEY (role_id) REFERENCES nb_roles (id) ON DELETE SET NULL',
];
