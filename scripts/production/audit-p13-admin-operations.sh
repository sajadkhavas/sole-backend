#!/usr/bin/env bash
set -euo pipefail

required_resources=(
  Orders PaymentAttempts PaymentReconciliations Shipments ReturnRequests RefundRequests
  SupportCases ProductReviews NotificationDeliveryAttempts LoyaltyLedgerEntries
)

for resource in "${required_resources[@]}"; do
  test -d "app/Filament/Resources/${resource}" || { echo "Missing P13 resource: ${resource}" >&2; exit 1; }
done

grep -q "class AdminOperationsService" app/Services/Operations/AdminOperationsService.php
grep -q "operations-manager" config/sole.php
grep -q "reconcileForOperations" app/Services/Commerce/PaymentService.php
grep -q "Support case state may only change" app/Models/SupportCase.php
grep -q "Submitted review evidence is immutable" app/Models/ProductReview.php

if grep -R -E "EditAction|DeleteAction" app/Filament/Resources/{Orders,PaymentAttempts,PaymentReconciliations,Shipments,ReturnRequests,RefundRequests,SupportCases,ProductReviews,NotificationDeliveryAttempts,LoyaltyLedgerEntries}; then
  echo "P13 operational truth must not expose generic edit/delete actions." >&2
  exit 1
fi

echo "P13 admin operations contracts: PASS"
