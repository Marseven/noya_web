<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Str;

class AuthController extends BaseController
{
    /**
     * @OA\Post(
     *      path="/api/v1/auth/login",
     *      operationId="login",
     *      tags={"Authentication"},
     *      summary="User login",
     *      description="Login user with email and password",
     *      security={{"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"email","password"},
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="password", type="string", format="password", example="password123"),
     *              @OA\Property(property="otp", type="string", example="123456", description="Required if 2FA is enabled")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Successful login",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Login successful"),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="user", type="object"),
     *                  @OA\Property(property="token", type="string", example="1|abc123..."),
     *                  @OA\Property(property="token_type", type="string", example="Bearer"),
     *                  @OA\Property(property="expires_at", type="string", format="date-time")
     *              )
     *          )
     *      ),
     *      @OA\Response(
     *          response=401,
     *          description="Invalid credentials or 2FA required",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Invalid credentials"),
     *              @OA\Property(property="requires_2fa", type="boolean", example=true)
     *          )
     *      ),
     *      @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=false),
     *              @OA\Property(property="message", type="string", example="Validation Error"),
     *              @OA\Property(property="errors", type="object")
     *          )
     *      )
     * )
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string',
            'otp' => 'nullable|string|size:6'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $credentials = $request->only('email', 'password');
        
        if (!Auth::attempt($credentials)) {
            return $this->sendUnauthorized('Invalid credentials');
        }

        $user = Auth::user();

        // Check if user is active
        if ($user->status !== 'APPROVED') {
            Auth::logout();
            return $this->sendUnauthorized('Account not approved');
        }

        // Check 2FA if enabled
        if ($user->is2FAEnabled()) {
            if (!$request->has('otp')) {
                Auth::logout();
                return response()->json([
                    'success' => false,
                    'message' => '2FA verification required',
                    'requires_2fa' => true
                ], 401);
            }

            $google2fa = new Google2FA();
            $valid = $google2fa->verifyKey($user->google_2fa_secret, $request->otp);

            if (!$valid) {
                Auth::logout();
                return $this->sendUnauthorized('Invalid OTP code');
            }
        }

        // Create token with 1 hour expiration
        $token = $user->createToken('API Token', ['*'], now()->addHour());

        return $this->sendResponse([
            'user' => new UserResource($user->load(['role.privileges', 'merchants'])),
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at->toISOString()
        ], 'Login successful');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/auth/register",
     *      operationId="register",
     *      tags={"Authentication"},
     *      summary="User registration",
     *      description="Register a new user",
     *      security={{"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"first_name","last_name","email","password","password_confirmation","merchant_id"},
     *              @OA\Property(property="first_name", type="string", example="John"),
     *              @OA\Property(property="last_name", type="string", example="Doe"),
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="password", type="string", format="password", example="password123"),
     *              @OA\Property(property="password_confirmation", type="string", format="password", example="password123"),
     *              @OA\Property(property="phone", type="string", example="+24101234567"),
     *              @OA\Property(property="merchant_id", type="integer", example=1)
     *          ),
     *      ),
     *      @OA\Response(
     *          response=201,
     *          description="User registered successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="User registered successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:50',
            'merchant_id' => 'required|exists:merchants,id',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $merchant = Merchant::query()
            ->where('id', (int) $request->merchant_id)
            ->where('status', 'APPROVED')
            ->first();

        if (!$merchant) {
            return $this->sendValidationError([
                'merchant_id' => ['Le marchand sélectionné est invalide ou non approuvé.'],
            ]);
        }

        $defaultUserRole = Role::query()
            ->whereRaw('LOWER(name) = ?', ['user'])
            ->first();

        if (!$defaultUserRole) {
            return $this->sendValidationError([
                'role_id' => ['Le rôle User est introuvable. Contactez un administrateur.'],
            ]);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => (int) $defaultUserRole->id,
            'status' => 'PENDING'
        ]);

        $user->merchants()->sync([(int) $merchant->id]);

        return $this->sendCreated(new UserResource($user->load(['role.privileges', 'merchants'])), 'User registered successfully');
    }

    /**
     * Public list of approved actors available during self-signup.
     */
    public function registrationMerchants()
    {
        $merchants = Merchant::query()
            ->where('status', 'APPROVED')
            ->select(['id', 'name', 'type'])
            ->orderBy('name')
            ->get();

        return $this->sendResponse($merchants, 'Registration merchants retrieved successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/auth/setup-2fa",
     *      operationId="setup2FA",
     *      tags={"Authentication"},
     *      summary="Setup 2FA",
     *      description="Generate QR code and recovery codes for 2FA setup",
     *      security={{"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"email"},
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="2FA setup data",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="2FA setup data generated"),
     *              @OA\Property(property="data", type="object",
     *                  @OA\Property(property="qr_code", type="string", description="Base64 encoded QR code image"),
     *                  @OA\Property(property="secret", type="string", example="JBSWY3DPEHPK3PXP"),
     *                  @OA\Property(property="recovery_codes", type="array", @OA\Items(type="string"))
     *              )
     *          )
     *      )
     * )
     */
    public function setup2FA(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();

        if ($user->is2FAEnabled()) {
            return $this->sendError('2FA is already enabled for this user');
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        
        // Generate recovery codes
        $recoveryCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $recoveryCodes[] = Str::random(10);
        }

        // Generate QR code URL
        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        // You would typically use a QR code library to generate the actual image
        // For now, we'll return the URL and secret
        
        return $this->sendResponse([
            'qr_code_url' => $qrCodeUrl,
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes
        ], '2FA setup data generated');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/auth/confirm-2fa",
     *      operationId="confirm2FA",
     *      tags={"Authentication"},
     *      summary="Confirm 2FA setup",
     *      description="Confirm 2FA setup with OTP verification",
     *      security={{"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              required={"email","secret","otp","recovery_codes"},
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *              @OA\Property(property="secret", type="string", example="JBSWY3DPEHPK3PXP"),
     *              @OA\Property(property="otp", type="string", example="123456"),
     *              @OA\Property(property="recovery_codes", type="array", @OA\Items(type="string"))
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="2FA enabled successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="2FA enabled successfully")
     *          )
     *      )
     * )
     */
    public function confirm2FA(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'secret' => 'required|string',
            'otp' => 'required|string|size:6',
            'recovery_codes' => 'required|array'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($request->secret, $request->otp);

        if (!$valid) {
            return $this->sendError('Invalid OTP code');
        }

        $user->update([
            'google_2fa_active' => true,
            'google_2fa_secret' => $request->secret,
            'google_2fa_recovery_codes' => $request->recovery_codes
        ]);

        return $this->sendResponse(null, '2FA enabled successfully');
    }

    /**
     * Start password reset flow after validating user + 2FA setup.
     */
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();
        if (!$user || !$user->is2FAEnabled()) {
            return $this->sendError('2FA must be enabled before resetting password', [], 422);
        }

        $token = $this->storePasswordResetToken($user->email);

        return $this->sendResponse([
            'reset_token' => $token,
            'expires_at' => $this->tokenExpiresAt(),
        ], 'Password reset token generated successfully');
    }

    /**
     * Check if a user can proceed to reset flow.
     */
    public function resetPasswordPrecheck(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();

        return $this->sendResponse([
            'two_fa_enabled' => (bool) ($user?->is2FAEnabled() ?? false),
        ], 'Reset precheck completed');
    }

    /**
     * Validate 2FA OTP and issue a temporary reset token.
     */
    public function verifyResetOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $user = User::where('email', $request->email)->first();
        if (!$user || !$user->is2FAEnabled()) {
            return $this->sendError('2FA is not enabled for this account', [], 422);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->google_2fa_secret, $request->otp);

        if (!$valid) {
            return $this->sendUnauthorized('Invalid OTP code');
        }

        $token = $this->storePasswordResetToken($user->email);

        return $this->sendResponse([
            'reset_token' => $token,
            'expires_at' => $this->tokenExpiresAt(),
        ], 'OTP verified successfully');
    }

    /**
     * Complete password reset using a valid reset token.
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        $table = config('auth.passwords.users.table', 'password_reset_tokens');
        $record = DB::table($table)->where('email', $request->email)->first();

        if (!$record || !isset($record->token)) {
            return $this->sendError('Invalid or expired reset token', [], 422);
        }

        if ($this->isResetTokenExpired($record->created_at ?? null)) {
            DB::table($table)->where('email', $request->email)->delete();
            return $this->sendError('Reset token has expired', [], 422);
        }

        if (!Hash::check($request->token, $record->token)) {
            return $this->sendError('Invalid or expired reset token', [], 422);
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return $this->sendNotFound('User not found');
        }

        $user->password = Hash::make($request->password);
        $user->remember_token = Str::random(60);
        $user->save();

        DB::table($table)->where('email', $request->email)->delete();

        return $this->sendResponse(null, 'Password reset successfully');
    }

    /**
     * @OA\Post(
     *      path="/api/v1/auth/logout",
     *      operationId="logout",
     *      tags={"Authentication"},
     *      summary="User logout",
     *      description="Logout user and revoke token",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Response(
     *          response=200,
     *          description="Logout successful",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Logout successful")
     *          )
     *      )
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return $this->sendResponse(null, 'Logout successful');
    }

    /**
     * @OA\Get(
     *      path="/api/v1/auth/profile",
     *      operationId="getProfile",
     *      tags={"Authentication"},
     *      summary="Get user profile",
     *      description="Get authenticated user profile",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\Response(
     *          response=200,
     *          description="User profile",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Profile retrieved successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function profile(Request $request)
    {
        $user = $request->user()->load(['role.privileges', 'merchants']);
        return $this->sendResponse(new UserResource($user), 'Profile retrieved successfully');
    }

    /**
     * @OA\Put(
     *      path="/api/v1/auth/profile",
     *      operationId="updateProfile",
     *      tags={"Authentication"},
     *      summary="Update user profile",
     *      description="Update authenticated user profile",
     *      security={{"bearerAuth": {}}, {"apiCredentials": {}}},
     *      @OA\RequestBody(
     *          required=true,
     *          @OA\JsonContent(
     *              @OA\Property(property="first_name", type="string", example="John"),
     *              @OA\Property(property="last_name", type="string", example="Doe"),
     *              @OA\Property(property="email", type="string", format="email", example="user@example.com")
     *          ),
     *      ),
     *      @OA\Response(
     *          response=200,
     *          description="Profile updated successfully",
     *          @OA\JsonContent(
     *              @OA\Property(property="success", type="boolean", example=true),
     *              @OA\Property(property="message", type="string", example="Profile updated successfully"),
     *              @OA\Property(property="data", type="object")
     *          )
     *      )
     * )
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'phone' => 'sometimes|nullable|string|max:50',
            'bio' => 'sometimes|nullable|string',
            'avatar_url' => 'sometimes|nullable|string|max:2048',
            'current_password' => 'required_with:password|string',
            'password' => 'sometimes|string|min:8|confirmed',
            'password_confirmation' => 'sometimes|string|min:8'
        ]);

        if ($validator->fails()) {
            return $this->sendValidationError($validator->errors()->toArray());
        }

        if ($request->filled('password') && !Hash::check((string) $request->current_password, (string) $user->password)) {
            return $this->sendValidationError([
                'current_password' => ['The current password is incorrect.']
            ]);
        }

        $data = $request->only(['first_name', 'last_name', 'email', 'phone', 'bio', 'avatar_url']);

        if ($request->filled('password')) {
            $data['password'] = Hash::make((string) $request->password);
        }

        $user->update($data);

        return $this->sendUpdated(new UserResource($user->load(['role.privileges', 'merchants'])), 'Profile updated successfully');
    }

    /**
     * Store (or rotate) password reset token for a given email.
     */
    private function storePasswordResetToken(string $email): string
    {
        $table = config('auth.passwords.users.table', 'password_reset_tokens');
        $token = Str::random(64);

        DB::table($table)->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        return $token;
    }

    /**
     * Get token expiry date in ISO format.
     */
    private function tokenExpiresAt(): string
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        return now()->addMinutes($minutes)->toISOString();
    }

    /**
     * Determine whether a stored reset token is expired.
     */
    private function isResetTokenExpired($createdAt): bool
    {
        if (!$createdAt) {
            return true;
        }

        $minutes = (int) config('auth.passwords.users.expire', 60);
        return Carbon::parse($createdAt)->addMinutes($minutes)->isPast();
    }
}
