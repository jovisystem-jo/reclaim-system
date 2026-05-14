package com.reclaim.mobile.api.network

import com.reclaim.mobile.models.ApiEnvelope
import com.reclaim.mobile.models.AuthData
import com.reclaim.mobile.models.ClaimSingleEnvelope
import com.reclaim.mobile.models.ClaimsEnvelope
import com.reclaim.mobile.models.DashboardEnvelope
import com.reclaim.mobile.models.ItemDetailEnvelope
import com.reclaim.mobile.models.ItemListEnvelope
import com.reclaim.mobile.models.NotificationsEnvelope
import com.reclaim.mobile.models.OptionsEnvelope
import com.reclaim.mobile.models.ProfileEnvelope
import com.reclaim.mobile.models.UserEnvelope
import okhttp3.MultipartBody
import okhttp3.RequestBody
import retrofit2.http.Field
import retrofit2.http.FormUrlEncoded
import retrofit2.http.GET
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Query

interface ApiService {
    @FormUrlEncoded
    @POST("auth/login.php")
    suspend fun login(
        @Field("email") email: String,
        @Field("password") password: String,
        @Field("device_name") deviceName: String = "Android App"
    ): ApiEnvelope<AuthData>

    @FormUrlEncoded
    @POST("auth/register.php")
    suspend fun register(
        @Field("name") name: String,
        @Field("email") email: String,
        @Field("username") username: String,
        @Field("password") password: String,
        @Field("confirm_password") confirmPassword: String,
        @Field("student_staff_id") studentStaffId: String,
        @Field("department") department: String,
        @Field("phone") phone: String,
        @Field("device_name") deviceName: String = "Android App"
    ): ApiEnvelope<AuthData>

    @GET("auth/me.php")
    suspend fun me(): ApiEnvelope<UserEnvelope>

    @POST("auth/logout.php")
    suspend fun logout(): ApiEnvelope<Unit>

    @GET("dashboard.php")
    suspend fun dashboard(): ApiEnvelope<DashboardEnvelope>

    @GET("items/index.php")
    suspend fun items(
        @Query("scope") scope: String,
        @Query("query") query: String? = null,
        @Query("status") status: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 15
    ): ApiEnvelope<ItemListEnvelope>

    @GET("items/show.php")
    suspend fun itemDetail(
        @Query("id") itemId: Int
    ): ApiEnvelope<ItemDetailEnvelope>

    @Multipart
    @POST("items/report.php")
    suspend fun reportItem(
        @Part("title") title: RequestBody,
        @Part("category") category: RequestBody,
        @Part("brand") brand: RequestBody,
        @Part("color") color: RequestBody,
        @Part("description") description: RequestBody,
        @Part("location") location: RequestBody,
        @Part("date_occurred") dateOccurred: RequestBody,
        @Part("time_occurred") timeOccurred: RequestBody,
        @Part("delivery_option") deliveryOption: RequestBody,
        @Part("status") status: RequestBody,
        @Part image: MultipartBody.Part? = null
    ): ApiEnvelope<ItemDetailEnvelope>

    @FormUrlEncoded
    @POST("claims/submit.php")
    suspend fun submitClaim(
        @Field("item_id") itemId: Int,
        @Field("claimant_description") claimantDescription: String
    ): ApiEnvelope<ClaimSingleEnvelope>

    @GET("claims/index.php")
    suspend fun claims(): ApiEnvelope<ClaimsEnvelope>

    @FormUrlEncoded
    @POST("claims/cancel.php")
    suspend fun cancelClaim(
        @Field("claim_id") claimId: Int
    ): ApiEnvelope<Unit>

    @FormUrlEncoded
    @POST("claims/complete.php")
    suspend fun completeClaim(
        @Field("claim_id") claimId: Int
    ): ApiEnvelope<Unit>

    @GET("notifications/index.php")
    suspend fun notifications(
        @Query("filter") filter: String = "all",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 20
    ): ApiEnvelope<NotificationsEnvelope>

    @FormUrlEncoded
    @POST("notifications/mark-read.php")
    suspend fun markNotificationRead(
        @Field("notification_id") notificationId: Int
    ): ApiEnvelope<Unit>

    @POST("notifications/mark-all-read.php")
    suspend fun markAllNotificationsRead(): ApiEnvelope<Unit>

    @GET("profile.php")
    suspend fun profile(): ApiEnvelope<ProfileEnvelope>

    @FormUrlEncoded
    @POST("profile.php")
    suspend fun updateProfile(
        @Field("name") name: String,
        @Field("phone") phone: String,
        @Field("department") department: String,
        @Field("student_staff_id") studentStaffId: String,
        @Field("current_password") currentPassword: String = "",
        @Field("new_password") newPassword: String = ""
    ): ApiEnvelope<ProfileEnvelope>

    @GET("meta/options.php")
    suspend fun options(): ApiEnvelope<OptionsEnvelope>
}
