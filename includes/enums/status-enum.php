<?php

defined( "ABSPATH" ) || exit( "Direct Access Not Allowed" );

enum Status_Enum: string {
    case PENDING   = 'Pending';
    case COMPLETED = 'Completed';
    case FAILED    = 'Failed';
    case SKIPPED   = 'Skipped';
}