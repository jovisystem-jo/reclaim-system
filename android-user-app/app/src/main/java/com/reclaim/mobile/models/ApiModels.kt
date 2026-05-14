package com.reclaim.mobile.models

import com.google.gson.annotations.SerializedName

data class ApiEnvelope<T>(
    val success: Boolean,
    val message: String,
    @SerializedName("error_code") val errorCode: String? = null,
    val errors: Map<String, String?>? = null,
    val data: T? = null
)

data class AuthData(
    val token: String,
    @SerializedName("token_type") val tokenType: String,
    @SerializedName("expires_in") val expiresIn: Long,
    val user: User
)

data class UserEnvelope(
    val user: User
)

data class User(
    val id: Int,
    val name: String,
    val email: String,
    val username: String,
    val role: String,
    @SerializedName("student_staff_id") val studentStaffId: String,
    val department: String,
    val phone: String,
    @SerializedName("profile_image_url") val profileImageUrl: String? = null,
    @SerializedName("email_verified") val emailVerified: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("last_login") val lastLogin: String? = null
)

data class DashboardEnvelope(
    val user: User,
    val stats: DashboardStats,
    @SerializedName("recent_notifications") val recentNotifications: List<AppNotification>,
    @SerializedName("recent_items") val recentItems: List<Item>,
    @SerializedName("recent_claims") val recentClaims: List<Claim>
)

data class DashboardStats(
    @SerializedName("my_reports") val myReports: Int = 0,
    @SerializedName("my_claims") val myClaims: Int = 0,
    @SerializedName("approved_claims") val approvedClaims: Int = 0,
    @SerializedName("unread_notifications") val unreadNotifications: Int = 0
)

data class ItemListEnvelope(
    val scope: String,
    val items: List<Item>,
    val pagination: Pagination,
    val stats: ItemStats? = null
)

data class ItemStats(
    val total: Int = 0,
    @SerializedName("lost_count") val lostCount: Int = 0,
    @SerializedName("found_count") val foundCount: Int = 0,
    @SerializedName("returned_count") val returnedCount: Int = 0
)

data class ItemDetailEnvelope(
    val item: Item
)

data class Item(
    val id: Int,
    val title: String,
    val description: String,
    val category: String,
    val brand: String,
    val color: String,
    val status: String,
    val location: String,
    @SerializedName("found_location") val foundLocation: String,
    @SerializedName("delivery_location") val deliveryLocation: String,
    @SerializedName("date_found") val dateFound: String? = null,
    @SerializedName("reported_date") val reportedDate: String? = null,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("reported_by") val reportedBy: Int? = null,
    @SerializedName("reporter_name") val reporterName: String? = null,
    @SerializedName("reporter_profile_image_url") val reporterProfileImageUrl: String? = null,
    @SerializedName("claim_count") val claimCount: Int? = null,
    @SerializedName("user_has_claimed") val userHasClaimed: Boolean? = null,
    @SerializedName("can_claim") val canClaim: Boolean? = null,
    @SerializedName("reported_by_current_user") val reportedByCurrentUser: Boolean? = null,
    @SerializedName("similar_items") val similarItems: List<Item>? = null
)

data class ClaimsEnvelope(
    val stats: ClaimStats,
    val claims: List<Claim>
)

data class ClaimStats(
    val total: Int = 0,
    val pending: Int = 0,
    val approved: Int = 0,
    val rejected: Int = 0,
    val completed: Int = 0,
    val cancelled: Int = 0
)

data class ClaimSingleEnvelope(
    val claim: Claim
)

data class Claim(
    val id: Int,
    @SerializedName("item_id") val itemId: Int,
    @SerializedName("claimant_id") val claimantId: Int,
    val status: String,
    @SerializedName("claimant_description") val claimantDescription: String,
    @SerializedName("admin_notes") val adminNotes: String,
    @SerializedName("proof_image_url") val proofImageUrl: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("verified_date") val verifiedDate: String? = null,
    val item: ClaimItem
)

data class ClaimItem(
    val id: Int,
    val title: String,
    val description: String,
    val status: String,
    val category: String,
    @SerializedName("found_location") val foundLocation: String,
    @SerializedName("image_url") val imageUrl: String? = null,
    @SerializedName("reporter_name") val reporterName: String? = null
)

data class NotificationsEnvelope(
    val notifications: List<AppNotification>,
    val pagination: Pagination,
    @SerializedName("unread_count") val unreadCount: Int
)

data class AppNotification(
    val id: Int,
    val title: String,
    val message: String,
    val type: String,
    @SerializedName("is_read") val isRead: Boolean,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("time_ago") val timeAgo: String? = null
)

data class ProfileEnvelope(
    val user: User,
    val stats: ProfileStats? = null,
    val activities: List<ActivityEntry> = emptyList()
)

data class ProfileStats(
    @SerializedName("total_reports") val totalReports: Int = 0,
    @SerializedName("total_claims") val totalClaims: Int = 0,
    @SerializedName("approved_claims") val approvedClaims: Int = 0,
    @SerializedName("unread_notifications") val unreadNotifications: Int = 0
)

data class ActivityEntry(
    val type: String,
    @SerializedName("reference_id") val referenceId: Int,
    @SerializedName("activity_date") val activityDate: String,
    val action: String
)

data class OptionsEnvelope(
    val departments: List<String>,
    val categories: List<String>,
    val colors: List<String>,
    @SerializedName("delivery_locations") val deliveryLocations: List<String>,
    @SerializedName("brands_by_category") val brandsByCategory: Map<String, List<String>>
)

data class Pagination(
    val total: Int,
    val page: Int,
    @SerializedName("per_page") val perPage: Int,
    @SerializedName("total_pages") val totalPages: Int
)
